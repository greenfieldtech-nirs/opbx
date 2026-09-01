# Deployment

## With the OPBX stack (recommended)

The server is a service in the root `docker-compose.yml`:

```bash
docker compose up -d --build mcp-server
```

- Container: `opbx_mcp`, internal `OPBX_BASE_URL=http://nginx`, host port `${MCP_PORT:-8080}`.
- Healthcheck built in (`wget` on `/health`); restart policy `unless-stopped`.
- Gotcha: rebuilding a service can restart `app` with a new container IP while nginx
  caches the old upstream → 502s. Fix: `docker compose restart nginx`.

## Standalone

```bash
cd mcp-server
cp .env.example .env   # set OPBX_BASE_URL (reachable from the container)
docker compose up -d --build
# optional local tracing: docker compose --profile telemetry up -d
```

## Environment

See `.env.example`. Key variables:

| Variable | Default | Purpose |
|---|---|---|
| `OPBX_BASE_URL` | — (required) | OPBX base URL (no trailing slash) |
| `PORT` / `HOST` | 8080 / 0.0.0.0 | Listen address |
| `OPBX_TIMEOUT_MS` | 15000 | Per-call upstream timeout |
| `AUTH_IDENTITY_CACHE_TTL_SECONDS` | 300 | `/auth/me` identity cache TTL |
| `RATE_LIMIT_READ_PER_MIN` / `_WRITE_` / `_SENSITIVE_` | 120 / 30 / 10 | Per-identity rate classes |
| `LOG_LEVEL` | info | Pino level |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — (disabled) | OTLP/HTTP endpoint for traces |

No secrets are configured server-side; credentials arrive per-request from MCP clients.

## Production notes

- **Scaling**: stateless — run N replicas behind a load balancer, no sticky sessions.
  Rate limits are per-process (effective limit = N × configured); OPBX's own 60/min
  per-organization limiter is the backstop.
- **TLS**: terminate at your load balancer/reverse proxy; the container speaks plain HTTP.
- **Graceful shutdown**: SIGTERM/SIGINT close Fastify and flush OTel before exit.
- **Non-root**: the runtime image runs as user `opbx`; Node 24 Alpine, prod deps only.
- **Health**: `/health` (liveness), `/ready` (readiness incl. OPBX reachability probe).
