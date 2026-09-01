# OPBX MCP Server — Implementation Report

**Date:** 2026-08-26 · **Branch:** `feature/mcp-server` · **Status:** Phases 1–9 complete

## Implemented

**MCP server** `opbx-mcp` (Node 24 LTS, TypeScript, Fastify 5, `@modelcontextprotocol/sdk`
1.30.0 verified against the installed package, Zod v4, OpenTelemetry, Pino, Vitest, Docker).

- **107 tools** — every one mapped to a validated OPBX REST operation:
  - Extensions (7), Phone numbers/DIDs (5 incl. composite routing), Ring groups (7),
    IVR (8), Business hours (9), Conference rooms (7), AI providers/assistants (8),
    AI load balancers (2 reads), Campaigns (14), Distribution lists (13),
    CDRs/calls (3), Live calls (5), Inbound blacklist (7), Outbound whitelist (5),
    Users (7), Supervisors/dashboard (2), Recordings metadata (2), Call tracking reads (5),
    Organization identity (1), `validate_configuration` (1).
  - Full per-tool reference: [docs/mcp-tools.md](docs/mcp-tools.md) (registry-generated).
- **16 resources** (`opbx://organization` + 15 entity templates) — read-only, RBAC-matched
  to the corresponding tools: [docs/mcp-resources.md](docs/mcp-resources.md).
- **4 prompts** — `configure_pbx`, `build_inbound_call_flow`, `create_outbound_campaign`,
  `diagnose_call_problem`: [docs/mcp-prompts.md](docs/mcp-prompts.md).

## OPBX mapping

Complete tool ↔ operationId ↔ method/path ↔ permission ↔ risk ↔ confirmation table:
[docs/functional-validation.md](docs/functional-validation.md) (generated from the
registry; machine-verified by contract tests against the OpenAPI spec).

## Excluded APIs (intentionally)

Per [docs/opbx-api-inventory.md](docs/opbx-api-inventory.md) (all 267 operations classified):

- **Execution plane (24 ops)** — CXML voice routing, Cloudonix/dialer webhooks, dialer-worker
  API (incl. `initiateCall`), public signed recording URLs, DNI swap. CPaaS-facing, never agent-callable.
- **Platform admin (16 ops)** — `/v1/platform/*` incl. operate-as impersonation; reserved for a
  future separately-privileged MCP server. MCP never sends `X-Operate-As-Organization`.
- **Auth infrastructure (16)** — login/logout/register/refresh/Auth0/invite-accept/CSRF/broadcasting.
  (`GET /auth/me` is used internally for identity resolution only.)
- **Internal/sensitive (91)** — SIP passwords, extension password reset, webphone/embed
  credentials, Cloudonix settings/keys, API-key management, CSV upload/download flows
  (JSON batch provided instead), profile/password management, health endpoints,
  call-notification webhook config, call-tracking writes (read-only in v1).

## Security

- **Auth:** pass-through of the caller's OPBX credential (PAT via `/auth/me`, or `opbxk_`
  scoped key with upstream scope enforcement). Server stores nothing. Tenant is always
  credential-derived; `organization_id` arguments are never accepted/forwarded.
- **RBAC:** per-invocation role checks mirroring `app/Policies/*`, stricter than upstream
  where OPBX has none (IVR mutations, status toggles).
- **Confirmation:** two-step `confirm: true` with live impact previews for all destructive/
  high-impact operations (chosen over SDK elicitation for client independence).
- **Rate limits:** edge + per-tool classes; upstream per-org limiter as backstop.
- **Secrets:** SIP/Cloudonix/API-key surfaces excluded; pino redaction; upstream 5xx bodies
  sanitized (PHP internals leak observed and fixed).

## Validation performed

- **279 automated tests** (unit, contract, security, env-gated live integration) — all green.
- **Contract tests** parse the real modular OpenAPI spec and pin every tool/resource
  operationId/method/path; type generation pins request/response shapes at compile time.
- **Live verification** against the local OPBX Docker stack for every tool group:
  full CRUD cycles, campaign lifecycle (start/pause/resume/archive), delete flows with
  preview gates, RBAC denial (reporter/pbx_user), API-key paths (granted + denied),
  `validate_configuration` detecting deliberately broken references (DID→inactive
  extension cascade), error normalization (422/403/404/409/429/500-DELETE_ERROR).
- **Docker**: image builds (multi-stage, non-root), runs as `mcp-server` service in the
  root compose stack, `/health` + `/ready` verified through the internal network.

## API discrepancies found (spec/source vs documentation)

22 documented in [docs/opbx-api-inventory.md](docs/opbx-api-inventory.md#openapisource-discrepancies-must-fix-or-document).
Highest-impact:

1. Ring-group spec is badly stale (wrong strategy/fallback enums; update request body is
   actually the response schema).
2. **Assigning a ready list to a draft campaign silently ACTIVATES it**
   (`ListManagementService::assignListToCampaign`) — bypassing the normal start flow.
   MCP gates it behind confirmation with an explicit warning.
3. IVR mutations and business-hours/IVR status toggles have **no authorization** upstream.
4. In-use deletes return HTTP 500 `DELETE_ERROR` instead of 409 (swallowed exception);
   MCP normalizes to `resource_in_use`.
5. Session endpoints 500 with PHP internals on non-numeric IDs; MCP constrains inputs
   and sanitizes upstream 5xx.
6. Campaign/session route groups are Sanctum-only — `opbxk_` keys get bare 401s there.

## Known limitations / remaining recommendations

- **Tool count (107)** exceeds the original ~35–50 guidance — the approved inventory
  covered more ground (supervisors, call tracking reads, full distribution-list
  lifecycle). The surface is curated (no REST proxy, merged PUT/PATCH), but a future
  "profile" mechanism could expose role-based subsets at `tools/list` time.
- **PAT longevity**: Sanctum PATs die in 24h — document API keys as the agent credential
  (done in docs/authentication.md). A future OPBX `/auth/me`-for-keys endpoint would
  enable richer identity for key principals.
- **Elicitation**: if MCP clients broadly adopt elicitation, the confirmation gate can
  upgrade to interactive prompts without changing tool definitions.
- **v2 candidates**: call-tracking writes, notification settings/logs, recordings
  upload/delete, ALB writes, supervisor assignment management, extension sync tools,
  platform-manager MCP server (separate deployment).
- **CI integration job** boots the full OPBX stack; it's heavy and gated to
  develop-pushes/manual runs.
