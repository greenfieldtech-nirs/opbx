# Architecture

## Planes

opbx-mcp sits strictly in the **control plane**. OPBX's execution plane (CXML voice
routing, Cloudonix webhooks, dialer-worker APIs, AMD worker) is never exposed —
those endpoints are classified `EXECUTION_PLANE` in
[opbx-api-inventory.md](opbx-api-inventory.md) and structurally unreachable through MCP.

```
MCP Client
  │  POST/GET/DELETE /mcp  (MCP Streamable HTTP, stateless — sessionIdGenerator: undefined)
  ▼
Fastify (src/server/http.ts)
  │  edge rate limit → Bearer extraction → IdentityResolver
  ▼
McpRequestContext { identity, opbx: OpbxClient, requestId, traceId }
  ▼
McpServer built per request (src/server/mcp.ts)
  │  tool wrapper: RBAC → rate class → confirmation gate → handler → error mapping
  ▼
OpbxClient (src/opbx/client.ts) — typed against generated OpenAPI types
  ▼
OPBX REST API
```

## Key design decisions

- **Stateless Streamable HTTP.** A fresh `McpServer` + transport per HTTP request
  (official SDK v1.30 stateless pattern). No session state → trivial horizontal scaling;
  no sticky sessions required.
- **Identity resolution at the edge.** PATs are resolved once per request (cached 5 min
  by token hash) via `GET /api/v1/auth/me`; `opbxk_` keys skip resolution (no upstream
  identity endpoint exists) and rely on OPBX's deny-by-default scope enforcement.
- **Tenancy is structural.** OPBX derives the organization from the credential
  (`OrganizationScope`). MCP never accepts, stores, or forwards an organization id.
- **Tool factories.** `defineListTool` / `defineGetTool` / `defineRawReadTool` /
  `defineWriteTool` / `defineDeleteTool` (src/tools/factory.ts) keep the 107 tools
  uniform: zod v4 input schemas, policy metadata, normalized output.
- **Semantic composites** (`src/tools/validation.ts`): `configure_phone_number_routing`
  (pre-validates the target, then writes) and `validate_configuration`
  (multi-collection referential audit, `src/opbx/services/config-validator.ts`).
- **Confirmation model**: two-step `confirm: true` with live preview, chosen over SDK
  elicitation because it works with every MCP client (no capability negotiation).
  See docs/security.md.

## Error handling

OPBX uses five different error shapes; all normalize to a stable taxonomy
(`validation_error`, `authentication_error`, `authorization_error`, `not_found`,
`conflict`, `resource_in_use`, `rate_limited`, `upstream_error`, `network_error`) in
`src/opbx/errors.ts`. Upstream quirks handled: 500 `DELETE_ERROR` → `resource_in_use`;
`AI_ASSISTANT_IN_USE` (422) → `resource_in_use`; cross-tenant 404 masking preserved;
5xx bodies are replaced with a generic message (they can leak PHP internals).

## Observability

- **Pino** structured logs with credential redaction (`authorization`, `*token*`,
  `*password*`, `*secret*` paths censored) and per-tool context
  (request_id, trace_id, mcp_tool, organization_id, user_id).
- **OpenTelemetry** (OTLP/HTTP, enabled when `OTEL_EXPORTER_OTLP_ENDPOINT` is set):
  Fastify/fetch auto-instrumentation + one span per tool invocation with MCP/OPBX
  attributes.
- `/health` (process) and `/ready` (cheap OPBX `/api/health` probe, 3s timeout).

## Rate limiting

Two layers: a coarse per-credential edge limit (300 req/min) and per-tool rate classes
(`read` 120/min, `write` 30/min, `sensitive`/`campaign`/`live_call`/`bulk` 10/min —
configurable). In-memory sliding window per process; with N replicas the effective
limit is N× — the upstream 60/min per-organization limiter is the real backstop.

## Scaling

```
        Load Balancer
        /    |     \
       v     v      v
     MCP1  MCP2   MCP3        (stateless, any replica serves any request)
        \    |     /
         OPBX REST API
```

The only per-process state is the identity cache and rate-limit windows, both
safe to lose or duplicate.
