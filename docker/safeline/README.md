# SafeLine WAF — proof of concept (branch `poc/safeline-waf`)

`./install.sh` sets all of this up when you answer yes to the WAF question, and `./install.sh reconfigure` turns it
on or off or changes its mode. It shows the host steps (state directory, SELinux relabel, detector listener) and waits
for you to complete them. The manual steps below are kept as reference.

The edge nginx (`opbx_nginx`) becomes OpenResty and asks the SafeLine CE
detector about every request through
[lua-resty-t1k](https://github.com/chaitin/lua-resty-t1k) (T1K protocol, TCP 8000).
Routing, TLS and the OPBX vhosts are unchanged. SafeLine's own Tengine
reverse proxy is not used for traffic.

```
client ─TLS─▶ ssl-proxy ─▶ opbx_nginx (OpenResty + t1k) ─▶ frontend / app / soketi / mcp / grafana
                                 │ access_by_lua, :8000
                                 ▼
                     safeline-detector ◀── mgt (console, via opbx_nginx :8443) / fvm / luigi / chaos / pg
```

| File | Purpose |
|---|---|
| `docker/safeline/compose.yaml` | Official SafeLine CE v9.4.3 compose, kept verbatim (see header) |
| `docker/safeline/compose.override.yaml` | OPBX changes: console port not published (reached through opbx_nginx) |
| `docker/safeline/.env.example` | Images, secrets, state dir |
| `docker/nginx/Dockerfile.waf` | OpenResty 1.27.1.2 + lua-resty-t1k 1.2.1 (sha256 pinned) |
| `docker/nginx/waf/00-safeline-waf.conf` | http-level hook: mode, exemptions, fail-open |
| `docker-compose.waf.yml` | Overlay: WAF image, `safeline-ce` network, `WAF_MODE` |

## Run it (manual reference)

```bash
# 1. SafeLine (once): env, state dir, detector TCP listener
cp docker/safeline/.env.example docker/safeline/.env   # fill secrets (commands inside)
sudo mkdir -p /var/lib/safeline/resources/{postgres/data,mgt,sock,detector,nginx,chaos,cache,luigi,run} \
              /var/lib/safeline/logs/{nginx,detector}
printf 'bind_addr: 0.0.0.0\nlisten_port: 8000\n' | sudo tee /var/lib/safeline/resources/detector/detector.yml
sudo chcon -R -t container_file_t /var/lib/safeline    # SELinux hosts only (incl. Podman machine)
#   (macOS + Podman: run the sudo lines via `podman machine ssh`)
docker compose --project-directory docker/safeline -p safeline up -d

# 2. OPBX with the WAF hook
WAF_MODE=monitor docker compose -f docker-compose.yml -f docker-compose.waf.yml up -d   # off|monitor|block

# Console: needs WAF_CONSOLE_ENABLED=true. opbx_nginx serves it on port 9443 (WAF_CONSOLE_PORT moves it);
# SafeLine's own port mapping is removed in compose.override.yaml. Restrict it with WAF_CONSOLE_ALLOW
# (comma-separated IPs/CIDRs); the installer sets that only when you give a proxy IP.
# Behind a TLS proxy, forward port 9443 as well as the main port.
# Credentials: docker exec safeline-mgt /app/mgt reset-admin
# Would-block events: docker exec opbx_nginx grep safeline-waf /var/log/nginx/opbx_error.log
```

## Results (2026-11-10, Apple Silicon, Podman 5.7 machine, SafeLine 9.4.3 arm64 images)

| Test | Result |
|---|---|
| SQLi (tautology, UNION, in a JSON body), XSS (script, svg), path traversal, command injection, SSRF | **Detected**; `block` mode returns a JSON 403 in 2–7 ms, before Laravel |
| Log4Shell `${jndi:…}` in User-Agent, a custom header or the query | **Not detected** with default rules (not relevant to the PHP stack) |
| Benign: SPA, login, `O'Brien` / "select a ring group" queries, MCP, Soketi WebSocket upgrade, 100 KB JSON | **No false positives** |
| Cloudonix callbacks (`/api/webhooks/*`, `/api/voice/*`, `/api/callbacks/*`) in `block` mode | Inspected and logged, never blocked (by design) |
| Internal callers (dialer-worker, MCP → `http://nginx`) | No blocks seen |
| Detector stopped | Fails open; no added latency; error logged |
| Detector hung (`docker pause`) | Fails open after the 500 ms read timeout, **on every request** |
| Attack placed after 2 MB of body padding | **Not detected** (t1k inspects the first 1 MB of the body) |
| Overhead, GET / small JSON | Within noise (≈ −5 % req/s) |
| Overhead, 100 KB JSON | 535 → 292 req/s; p50 34 → 57 ms; p99 80 → 188 ms |
| Events in the SafeLine console DB | Yes (`mgt_detect_log_basic`), with attack type, source IP and event_id |

## Findings to resolve before production

1. **The GitHub compose for 9.4.3 is broken.** It lacks `DETECTOR_MGMT_TOKEN`, so the detector rejects rule pushes
   ([#1346](https://github.com/chaitin/SafeLine/issues/1346)). Use the release compose
   from `waf.chaitin.com/release/latest/`; it has no versioned URL, so we vendor and checksum it.
2. **The console doesn't reflect enforcement.** Every event is stored as `action=1`, whether nginx blocked it,
   only monitored it, or exempted it. Our nginx log is the source of truth for what happened.
3. **No "site" for detector-only traffic.** Events show `site_uuid=0`, and policy is the detector default.
   Per-path rules and exceptions need console configuration that we haven't explored.
4. **A hung detector adds 500 ms to every request.** Production needs a circuit breaker:
   a `lua_shared_dict` that skips detection for N seconds after a failure.
5. **Bodies over 1 MB are inspected only partially** (nginx accepts up to 50 MB). Raise `req_body_size`
   on the routes that matter, or cap body size there.
6. **Client IP comes from `X-Forwarded-For` only for trusted proxies.** nginx rewrites the client IP
   (`realip`, recursive) only when the sender is in `TRUSTED_PROXY_CIDR`; otherwise the socket address
   is used. The console allowlist (`WAF_CONSOLE_ALLOW`) sees the same address, so it is only as real
   as that list. Never trust a NAT or gateway address that direct traffic also arrives from (rootless
   Podman and Docker Desktop port publishing): a client could then spoof `X-Forwarded-For` to bypass
   both rate limits and the allowlist.
   The WAF hook sends the detector the same address (`$remote_addr`). Without `TRUSTED_PROXY_CIDR`,
   WAF events therefore show the proxy's IP, as rate limits do; set it to see real clients.
7. **Host requirements.** The state directory must be outside a read-only root (`/data` fails on Fedora
   CoreOS), SELinux hosts need the `container_file_t` relabel, and the detector's TCP listener is a
   manual edit to `detector.yml`.
8. **The upstream console listens on every interface** by default (publishing removed here; opbx_nginx serves it on `WAF_CONSOLE_PORT` (default 9443), gated by `WAF_CONSOLE_ENABLED`/`WAF_CONSOLE_ALLOW`), and
   `mgt` mounts `${SAFELINE_DIR}/resources/run`. 9.4.3 no longer mounts the host's `/var/run`.
9. **Footprint.** 7 more containers, including a second Postgres; images are about 480 MB on arm64.
10. **Restarts are slow under Podman.** Recreating `opbx_nginx` took about 100 s.
