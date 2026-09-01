# opbx-mcp

A production-grade **MCP (Model Context Protocol) server** that exposes a curated, secure,
agent-friendly semantic interface over the [OPBX](../README.md) REST API — the open-source
business PBX built on Cloudonix CPaaS.

It lets AI agents (Claude, etc.) safely operate a PBX: list and configure extensions,
route phone numbers, manage ring groups / IVR / business hours, inspect calls, run
auto-dialer campaigns, and audit the whole configuration — without ever touching the
OPBX database and without exposing execution-plane internals.

## Architecture

```
MCP Client (Claude, MCP Inspector, …)
    |  MCP over HTTP (Streamable HTTP, stateless)
    v
opbx-mcp  (Fastify, Node 24, TypeScript)
    |  HTTPS REST (Bearer pass-through)
    v
OPBX REST API  (/api/v1/…)
    v
OPBX application / MySQL / Redis / Cloudonix
```

- **Semantic facade, not a proxy.** Tools map to user intent (`configure_phone_number_routing`,
  `start_campaign`), not HTTP verbs. There is no generic "call any endpoint" tool.
- **Stateless.** No database, no sessions, no sticky anything; horizontally scalable behind a
  load balancer. All state lives in OPBX.
- **Every invocation is independently authorized** (RBAC re-checked per call), rate-limited,
  traced (OpenTelemetry), and structured-logged (Pino) with credential redaction.
- **Contract-tested** against the real OPBX OpenAPI spec — the build fails when OPBX drifts.

See [docs/architecture.md](docs/architecture.md) for details.

## Quick start (Docker, with the OPBX stack)

The server is a service in the root `docker-compose.yml`:

```bash
docker compose up -d --build mcp-server
curl http://localhost:8080/health
```

MCP endpoint: `http://localhost:8080/mcp` (host port via `MCP_PORT`).

Standalone (without the OPBX compose stack):

```bash
cd mcp-server
cp .env.example .env   # set OPBX_BASE_URL
docker compose up -d --build
```

## Authentication

MCP clients authenticate with **their own OPBX credential** as the Bearer token; the server
never issues or stores credentials:

| Credential | Format | Notes |
|---|---|---|
| OPBX Personal Access Token | Sanctum `id\|token` | Identity resolved via `GET /api/v1/auth/me` (cached 5 min). Expires after 24h and is revoked on any login/refresh/password change upstream — fine for interactive/dev use. |
| Scoped API key | `opbxk_…` | The durable machine credential (never expires; per-resource read/write grants, deny-by-default). Created by an org owner in OPBX Settings → API Keys. Campaigns/live-call route groups are Sanctum-only and reject keys. |

The organization is **always derived from the credential** — `organization_id` arguments are
never accepted and never sent upstream.

Example MCP client configuration:

```json
{
  "mcpServers": {
    "opbx": {
      "url": "http://localhost:8080/mcp",
      "headers": { "Authorization": "Bearer opbxk_your_key_here" }
    }
  }
}
```

See [docs/authentication.md](docs/authentication.md).

## Capabilities

- **107 tools** across extensions, phone numbers/DIDs, ring groups, IVR, business hours,
  conference rooms, AI assistants & load balancers, auto-dialer campaigns & distribution
  lists, call records & live calls, security lists (blacklist/whitelist), users, and a
  cross-resource `validate_configuration` audit.
- **16 resources** (`opbx://extensions/{id}`, …) — read-only JSON snapshots.
- **4 prompts** — `configure_pbx`, `build_inbound_call_flow`, `create_outbound_campaign`,
  `diagnose_call_problem`.

Full references: [docs/mcp-tools.md](docs/mcp-tools.md) ·
[docs/mcp-resources.md](docs/mcp-resources.md) · [docs/mcp-prompts.md](docs/mcp-prompts.md)

## Safety model

- High-impact operations (deletes, campaign lifecycle, call disconnect, coaching, security-rule
  removal) require a **two-step confirmation**: the first call returns a live preview
  (`confirmation_required: true`), execution only happens with `confirm: true`.
- MCP-side RBAC mirrors OPBX policies — and goes beyond them where OPBX is permissive
  (IVR mutations and status toggles have no upstream authorization; MCP enforces owner|pbx_admin).
- All upstream error shapes are normalized into stable error types; 5xx bodies are never
  forwarded (they can leak framework internals).

See [docs/security.md](docs/security.md).

## Development

```bash
cd mcp-server
npm ci
npm run dev                    # tsx watch, PORT=8080, needs OPBX_BASE_URL
npm test                       # unit + contract + security (integration is env-gated)
npm run validate:opbx-api      # OpenAPI drift check only
npm run generate:opbx-client   # regenerate typed client from ../docs/opbx-openapi
npm run generate:docs          # regenerate docs/mcp-tools.md etc. from the registry
```

Live integration tests (create/delete real test resources — use a disposable org):

```bash
OPBX_TEST_BASE_URL=http://localhost OPBX_TEST_TOKEN=<pat> npx vitest run tests/integration
```

MCP Inspector (manual):

```bash
npx @modelcontextprotocol/inspector
# Connect to http://localhost:8080/mcp (transport: Streamable HTTP)
# Set header: Authorization: Bearer <your OPBX token>
```

## Documentation

- [docs/architecture.md](docs/architecture.md) — design, request lifecycle, scaling
- [docs/authentication.md](docs/authentication.md) — credential model, identity resolution
- [docs/security.md](docs/security.md) — RBAC, confirmation, rate limits, secret handling
- [docs/opbx-api-inventory.md](docs/opbx-api-inventory.md) — all 267 OPBX operations classified, incl. 22 upstream discrepancies
- [docs/functional-validation.md](docs/functional-validation.md) — tool ↔ operation mapping
- [docs/openapi-drift.md](docs/openapi-drift.md) — how drift detection works
- [docs/deployment.md](docs/deployment.md) — Docker, env, health, OTel, production notes
- [IMPLEMENTATION_REPORT.md](IMPLEMENTATION_REPORT.md) — what was built, verified, excluded
