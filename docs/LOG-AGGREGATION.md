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
| `LOKI_RETENTION_HOURS` | `168` (7d) | Loki compactor retention |
| `OPBX_LOG_STACK` | `single,json` | Laravel channels inside containers (`json` = stdout contract) |

## Notes

- Rootless Podman needs `security_opt: label=disable` on the alloy service
  (already in compose) for Docker-socket access; harmless on Docker.
- nginx `/logs/` uses lazy upstream resolution, so the main app is unaffected
  when the profile is off (`/logs/` then 502s).
