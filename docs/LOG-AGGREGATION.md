# Centralized Log Aggregation (Loki + Grafana)

OPBX ships an optional, off-by-default log aggregation stack that collects
structured logs from all services into one searchable store.

## Components

| Service | Role |
|---------|------|
| **Loki** | Log store. 7-day retention (compactor), filesystem storage in the `loki_data` volume. |
| **Grafana** | Viewer, proxied by nginx at `<APP_URL>/logs/`. |
| **Alloy** | Collector. Tails OPBX containers over the Docker socket (opt-in label `logging.opbx/collect=true`), parses the JSON contract, pushes to Loki. |

## Enabling

```bash
# .env
LOG_STACK_ENABLED=true
GRAFANA_ADMIN_PASSWORD=<pick-a-password>

docker compose --profile logs up -d
```

Open `<APP_URL>/logs/` and log in with `admin` / `GRAFANA_ADMIN_PASSWORD`.
Two provisioned dashboards:

- **OPBX Call Flow Logs** (`/logs/d/opbx-logs-call-flow`) — everything related
  to call processing (voice webhooks, CXML decisions, queue/dialer/AMD
  activity). Filters: *Session token* (audit-trail one call end to end) and
  *Text search* (free text). Click any row to see the parsed entry as
  key/value pairs in the panel below.
- **OPBX Platform Logs** (`/logs/d/opbx-logs-platform`) — everything else
  (UI actions, provisioning, config). Same text search and click-to-inspect.

When the profile is off, the app still emits the same structured JSON to
stdout (`docker logs`); only the collection/UI is absent.

## Log line contract

Every service emits one JSON object per line: `ts`, `level`, `msg`,
`service`, `log_type` (`platform`|`call_flow`), plus contextual
`org_id`, `session_token`, `call_id` when known.

**Cardinality rule**: `session_token`/`call_id` are Loki *structured
metadata*, never labels. Labels are bounded: `service`, `level`,
`log_type`, `org_id`, `container`.

## Exporting logs to a file

- **CSV from the dashboard**: panel menu (⋮) → Inspect → Data → Download CSV.
- **Plain text**: `./scripts/export-logs.sh [--type call_flow|platform]
  [--session <token>] [--text <string>] [--all] [--since 24h] [file]` — renders
  the same lines as the dashboard (`[level] [token] message`, chronological).

## Useful queries (Explore → Loki)

```
# Audit trail for one call (session token from CDR / Cloudonix portal)
{log_type="call_flow"} | session_token="sess-abc123"

# All errors across the stack
{job="opbx"} | json | level="error"   # or: {job="opbx"} |= "error"

# One organization's call traffic
{log_type="call_flow", org_id="12"}

# Queue offers only
{service="acd-worker"} |~ "offering to agents"
```

Credentials (Authorization headers, API keys, passwords, tokens) are masked
by `App\Logging\MaskCredentialsProcessor` before records leave Laravel.
`session_token` is deliberately NOT masked — it is the audit-trail key.

## Configuration

| Env var | Default | Purpose |
|---------|---------|---------|
| `LOG_STACK_ENABLED` | `false` | Documented flag; the real switch is the compose profile |
| `GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD` | `admin` / (empty→`admin`) | Grafana login |
| `GRAFANA_ROOT_URL` | `http://localhost/logs/` | **Must match the URL the browser uses** (`<APP_URL>/logs/`) |
| `GRAFANA_CSRF_TRUSTED_ORIGINS` | (empty) | Extra hostnames reaching the UI (ngrok, second domain); space-separated, no scheme |
| `LOKI_RETENTION_HOURS` | `168` (7d) | Loki compactor retention |
| `OPBX_LOG_STACK` | `single,json` | Laravel channels inside containers (`json` = stdout contract) |

## Notes

- Rootless Podman needs `security_opt: label=disable` on the alloy service
  (already in compose) for Docker-socket access; harmless on Docker.
- nginx `/logs/` uses lazy upstream resolution, so the main app is unaffected
  when the profile is off (`/logs/` then 502s).

## Troubleshooting

**Dashboard panels stay empty and the browser console shows `403` with
`origin not allowed` on `POST /logs/api/ds/query`.**

Grafana's CSRF middleware compares the browser's `Origin` header against
`GF_SERVER_ROOT_URL` and rejects authenticated POSTs that do not match. It
rejects them *before* the request logger runs, so nothing appears in
`docker compose logs grafana` — only the browser sees the 403.

Set `GRAFANA_ROOT_URL` in `.env` to the public URL (`<APP_URL>/logs/`) and
recreate the container:

```bash
docker compose --profile logs up -d --force-recreate grafana
```

If the UI is also reached over other hostnames, list them in
`GRAFANA_CSRF_TRUSTED_ORIGINS` (space-separated, no scheme). The nginx
`/logs/` block forwards the real `Host`, so `root_url` is the single place
the public URL is configured.

**Verifying the pipeline without waiting for traffic.** Write one contract
line to a collected container's captured output and query Loki for it:

```bash
docker exec opbx_app sh -c 'echo "{\"ts\":\"$(date -u +%Y-%m-%dT%H:%M:%S.%6NZ)\",\"level\":\"info\",\"service\":\"laravel\",\"msg\":\"smoke test\",\"log_type\":\"platform\"}" > /proc/1/fd/2'
```

Note `/proc/1/fd/2`, not `fd/1`: in the php-fpm containers PID 1's stdout is
`/dev/null`, and Laravel's `php://stdout` reaches Docker only because php-fpm
re-emits worker output on stderr (`catch_workers_output = yes`).
