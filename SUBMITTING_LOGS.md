# Submitting Logs

OPBX ships an optional centralized logging stack (Grafana Loki + Grafana) that
aggregates structured logs from every service — Laravel, the dialer worker, the
ACD (call queue) worker, and the AMD worker — into a single searchable UI.

Use this guide when you need to collect logs for troubleshooting or attach a
per-call audit trail to a bug report.

---

## 1. Setup: Enabling the Log Stack

The stack is **off by default** and runs as an optional Docker Compose profile.

1. Edit your `.env` and set:

   ```bash
   LOG_STACK_ENABLED=true
   GRAFANA_ADMIN_PASSWORD=<pick-a-strong-password>
   ```

   Optional knobs (defaults shown):

   ```bash
   GRAFANA_ADMIN_USER=admin
   LOKI_RETENTION_HOURS=168        # 7 days of log retention
   ```

2. Start the stack with the `logs` profile:

   ```bash
   docker compose --profile logs up -d
   ```

3. Give it ~60 seconds to come up (three extra containers: `loki`, `alloy`,
   `grafana`). Verify:

   ```bash
   docker compose ps | grep -E "loki|alloy|grafana"
   ```

No application changes are needed — all services already emit structured JSON
logs; the profile only adds collection, storage, and the UI.

> **Note**: the log viewer sees **all tenants'** logs. Treat the Grafana
> credentials like admin credentials.

---

## 2. First Login to Grafana

1. Open **`http://localhost/logs/`** (Grafana is proxied through nginx — no
   extra ports are exposed). If you access OPBX through ngrok or a custom
   domain, use `<your-base-url>/logs/` instead.
2. Log in with:
   - **Username**: `admin` (or your `GRAFANA_ADMIN_USER`)
   - **Password**: your `GRAFANA_ADMIN_PASSWORD` (if you left it empty, the
     default is `admin` and Grafana asks you to change it on first login)

---

## 3. Accessing the Log Dashboards

Two dashboards are pre-provisioned (left sidebar → **Dashboards → OPBX**, or
direct links):

| Dashboard | URL | Contents |
|-----------|-----|----------|
| **OPBX Call Flow Logs** | `/logs/d/opbx-logs-call-flow` | Everything related to calls: voice webhooks, CXML routing decisions, queue/dialer/AMD activity |
| **OPBX Platform Logs** | `/logs/d/opbx-logs-platform` | Everything else: UI actions, configuration changes, provisioning |

Each log row shows: **timestamp**, **`[level]`** (color-coded: green = info,
yellow = warning, red = error), **`[session-token]`** when the entry relates to
a call, and the raw log entry.

**Click any row** to expand it and see the fully parsed entry as a readable
field-by-field table (message, session token, organization, event IDs, and all
contextual data).

Both dashboards auto-refresh every 10 seconds; the time window selector is in
the top-right corner (default: last 6 hours).

---

## 4. Filtering by Cloudonix Session Token

Every call-flow log entry that relates to a specific call carries its
**Cloudonix session token**. This is your audit-trail key.

At the top of the **Call Flow Logs** dashboard:

1. **Session token (audit trail)** — paste the session token (from the CDR,
   the Cloudonix portal, or a customer report). The panel now shows only
   entries for that call, across all services, in chronological order.
2. **Display Sessions Only** — enabled by default; shows only entries that
   carry a session token. Switch to *Disabled* to also see untagged entries.
3. **Text search** — free-text filter applied to every log line (works for
   phone numbers, extension numbers, error strings, etc.).

The session token filter and the text search can be combined.

---

## 5. Exporting Logs for a Bug Report

### Option A — Plain text file (recommended for submissions)

On the host, run the export script with the same filters as the dashboard:

```bash
# All entries for one call (audit trail):
./scripts/export-logs.sh --session <SESSION_TOKEN> call-audit.txt

# Wider net: all call-flow entries from the last 2 hours, including untagged:
./scripts/export-logs.sh --all --since 2h call-flow.txt

# Platform logs only:
./scripts/export-logs.sh --type platform --since 1h platform.txt
```

The file contains one rendered line per entry:

```
2026-10-08 06:04:26.305  [info] [b4583139f6d4447eb54dfd890bba6f20] {"ts":"...","msg":"CDR created successfully",...}
```

Run `./scripts/export-logs.sh --help` for all options.

### Option B — CSV from the browser

Hover the panel, click the **⋮** menu → **Inspect → Data → Download CSV**.
This exports exactly what your current filters show.

### Attaching to a bug report

1. Reproduce the problem (or identify the affected call).
2. Filter the Call Flow dashboard by the session token (section 4).
3. Export with the script (Option A).
4. Attach the `.txt` file to the bug report, along with the session token,
   approximate time of the call, and the calling/called numbers.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `http://localhost/logs/` shows a login page but dashboards are empty | Check the time window (top-right) and wait for traffic; logs start accumulating from stack start |
| `502` on `/logs/` | The `logs` profile isn't running: `docker compose --profile logs up -d` |
| "No data" with a session token filter | Token typo, or the call is older than retention (default 7 days) or outside the selected time window |
| Grafana layout looks broken | Hard-refresh (`Cmd/Ctrl+Shift+R`) — nginx proxies Grafana assets under `/logs/` |
