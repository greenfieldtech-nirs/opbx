# MCP Server (`mcp-server/`) — Module Memory

> **Last Updated**: 2026-09-09
> **Status**: ALL 9 PHASES COMPLETE + trunks module. 112 tools + 16 resources + 4 prompts, 287 tests green, running in root compose stack, CI workflow added.

## Trunk tools (2026-09-09, feature/trunk-management Phase 4b)

- `src/tools/trunks.ts` — 5 tools over `/v1/trunks` (listTrunks/createTrunk/getTrunk/updateTrunk/deleteTrunk): list_trunks (direction filter incl. public-*), get_trunk, create_trunk (superRefine: password requires username), update_trunk (no name/direction — immutable upstream), delete_trunk (defineDeleteTool w/ preview warnings re in_use_by whitelist refs).
- Trunks are Cloudonix-proxied (not stored locally); direction normalized inbound→public-inbound / outbound→public-outbound; password write-only; `in_use_by` lists referencing whitelist rules. List response has no meta — normalizeList synthesizes a single-page block.
- Gotcha: after adding tools you MUST `docker compose build mcp-server && docker compose up -d mcp-server` (from repo root) — the opbx_mcp container serves a baked dist/, not mounted source. Then `docker compose restart nginx` (podman IP-cache gotcha).
- mcp-test@example.com no longer exists in dev DB; ApiKey.created_by is nullable so keys can be minted without it.

## Phase 9 additions

- `scripts/generate-docs.ts` (`npm run generate:docs`) — regenerates docs/mcp-tools.md, mcp-resources.md, functional-validation.md from live registries (zod v4 z.toJSONSchema for input tables).
- Docs set: README.md, IMPLEMENTATION_REPORT.md, docs/{architecture,authentication,security,openapi-drift,deployment,mcp-prompts}.md (inventory + functional-validation from earlier phases).
- `.github/workflows/mcp-server.yml` — repo's first CI: build-test job (ci/lint/typecheck/test/validate:opbx-api/build) on mcp-server/** and docs/opbx-openapi/** changes; separate heavy `integration` job (boots OPBX compose stack, mints CI token via tinker, runs tests/integration) on develop pushes + manual.
- 502-vs-401 distinction: identity-resolution failures from OPBX unavailability now return 502 ("unreachable"), only real auth failures return 401.
- API-key scope denials (403 for opbxk_ principals) get a grant-specific suggested_action ("ask an owner to grant resource/level in Settings → API Keys") instead of the role-oriented one.
- Podman gotcha (recurring): rebuilding any service restarts `app` with a new IP; nginx caches the old upstream → 502s until `docker compose restart nginx` FROM REPO ROOT (running from mcp-server/ uses the wrong compose project).
- Final tool count: 112 (was 107; +5 trunks tools 2026-09-09)
- **2026-09-08 full access sweep** (74 tools x PAT + full-grant opbxk_ key, against live stack): after fixes, ALL pass. Fixes it drove: (a) OPBX session stats GROUP BY SQL bug (MIN(session_created_at)); (b) CDR statistics User type-hint broke on ApiKey principals (widened to User|ApiKey); (c) 'dashboard' added to GrantableResource (dashboard.supervisor now key-readable); (d) MCP maps HTTP 400 -> validation_error; (e) get/delete id args use z.coerce.number (OPBX serializes some ids as strings, e.g. business-hours). Sweep script: /tmp sweep-mcp.py (temp); MCP rate limiter correctly throttled the sweep's sensitive-class tail. (exceeds the ~35-50 guidance per the approved inventory; documented in IMPLEMENTATION_REPORT.md).

## Phase 8 additions

- `tests/security/security.test.ts` — 11 tests: no-credential 401, organization_id-in-args never sent upstream (incl. no X-Operate-As-Organization), path injection blocked (zod numeric ids), SSRF (client URL pinned to configured base), oversized/malformed args rejected (SDK surfaces schema errors as `result.isError` + "-32602" TEXT, not a JSON-RPC error field), RBAC denial precedes confirmation, confirmation bypass blocked (absent/false/string), per-credential identity cache isolation, pino redaction.
- `tests/integration/live.test.ts` — env-gated (OPBX_TEST_BASE_URL + OPBX_TEST_TOKEN) live suite: identity, list, full create→validate→confirm→delete ring-group cycle, validate_configuration against the real tenant. Skipped by default.
- Gotcha: SDK 1.30 wraps tool-arg schema failures as `result.isError` with "MCP error -32602" in content text (assert on that, not `error.code`).
- Gotcha (env): when capturing OPBX PATs from tinker output, never truncate (a `head -c 50` truncated token cost a debugging detour — stored hash mismatch = 401).
- MCP Inspector: manual verification documented in README (Phase 9); protocol smoke covered by tests.

## Phase 7 additions

- `src/resources/registry.ts` + `entities.ts` — 15 entity resource templates `opbx://<group>/{id}` (mirror get_* tools, same RBAC, operation refs type-checked + contract-tested) + static `opbx://organization` from identity.
- `src/prompts/index.ts` — configure_pbx, build_inbound_call_flow, create_outbound_campaign, diagnose_call_problem. registerPrompts(server) wired in buildMcpServer. Prompt argsSchema takes RAW zod shapes (not z.object) in SDK 1.30.
- **Gotcha**: resources/entities.js must be imported by src/tools/index.ts barrel — self-registration only runs on import; tests that import entities directly masked this initially.
- Resource RBAC: entity resources enforce requiredRoles in the read callback (McpError InvalidRequest); static org resource open to all authenticated callers.

## Phase 6 additions

- `src/opbx/services/config-validator.ts` — validateConfiguration(client): loads 11 collections via fetchAllPages (per_page=100, ≤10 pages), builds id→entity maps, cross-checks DID routing / ring-group members+fallback / IVR options+failover / BH prefixed actions / extension config+AI refs+user status / ALB members+fallback / campaign list+destination+caller-ID pool (detail fetch, ≤50 campaigns) / whitelist consistency. Defensive per-collection (COLLECTION_UNAVAILABLE info on failure). Issues: {severity, code, resource_type, resource_id, message, suggested_action}, sorted error→warning→info.
- `src/tools/validation.ts` — validate_configuration (rateClass bulk) + configure_phone_number_routing (pre-validates target active + ring-group has members, then PUTs routing). DESTINATION_OPS map: 7 destination types → read op + routing_config key (business_hours→business_hours_schedule_id).
- Ring-group DETAIL responses lack members_count/active_members_count (list has them) — fall back to members.length.
- configure_ai_call_routing deliberately not built (= configure_phone_number_routing with ai types; YAGNI).

## Phase 5 additions

- Confirmation mechanism: two-step `confirm: true` re-invocation (chosen over SDK elicitation — client-capability-independent). Unconfirmed calls to confirmation-gated tools return `{confirmation_required: true, preview: {...live data...}}`; preview fetched via read ops and NEVER mutates. ToolDefinition.preview hook; `defineDeleteTool` factory (delete + preview-get + warnings); `confirmField` zod helper.
- New tools: 12 deletes (extensions, phone-numbers, ring-groups, ivr, business-hours, conference-rooms, ai-assistants, campaigns, distribution-lists, users, blacklist, whitelist), 4 campaign lifecycle (start/pause/resume/archive, preview = campaign+list+monitor), disconnect_call (owner-only), start_call_coaching (spy|whisper|barge, owner|supervisor, returns dial destination, NO stop endpoint — hangup ends).
- 5xx sanitization: normalizeOpbxError never forwards upstream 5xx bodies (PHP internals leak observed).
- session_id args are numeric (SessionUpdateController params are `int`; non-numeric → uncaught TypeError 500 upstream).

## Major upstream discoveries (Phase 5)

- **assignListToCampaign auto-ACTIVATES draft campaigns** (ListManagementService.php:569-573) — no started_at/CAC reset; dialing can begin. assign tool is confirmation-gated with warning.
- Lifecycle state preconditions are policy-level (canStart/canPause in AutoDialerCampaignPolicy) → wrong state = 403 "This action is unauthorized." (not the service's 409/422). Documented in tool descriptions.
- `checkAndAutoStart` scheduler only touches auto_start=true DRAFT campaigns with ready lists within window.
- delete ops: mostly 204 → MCP returns {success:true, <key>_id:N}.

## What it is

`opbx-mcp`: a standalone, stateless MCP server (Node 24 + TypeScript + Fastify 5 + `@modelcontextprotocol/sdk` 1.30 + Zod v4 + OTel + Pino + Vitest) exposing a curated semantic tool surface over the OPBX REST API. Lives in `mcp-server/` inside this repo (branch `feature/mcp-server`). Never talks to MySQL/Redis directly; REST only.

## Key decisions (user-approved)

- **Location**: `mcp-server/` subdirectory of this repo.
- **Docker**: runs as `mcp-server` service in the ROOT docker-compose.yml (user decision, Option 2): `container_name: opbx_mcp`, `OPBX_BASE_URL=http://nginx`, host port `${MCP_PORT:-8080}`. Also added to tracked `docker-compose.yml.example` (root docker-compose.yml is gitignored). Verified end-to-end through the container (health/ready/tools-list/tools-call). Gotcha: rebuilding any service can restart `app` with a new IP while nginx caches the old upstream IP → 502s until `docker compose restart nginx` (podman, no resolver TTL). Standalone `mcp-server/docker-compose.yml` also works.
- **Auth**: pass-through of the client's OPBX credential. Accepts Sanctum PATs (validated via `GET /v1/auth/me`, cached per token-hash 5 min) and `opbxk_` API keys (no identity echo upstream; org/scopes enforced by OPBX `EnforceApiKeyScope`). Never accepts `organization_id` from tool args; never sends `X-Operate-As-Organization`.
- **Delivery**: phase-gated. Phases 1-5 approved/completed.

## Files (Phase 2)

- `src/config/index.ts` — zod-validated env config; `opbxApiBaseUrl = <base>/api` (spec paths carry /v1).
- `src/telemetry/logging.ts` — pino with credential redaction paths.
- `src/telemetry/tracing.ts` — OTel NodeSDK (OTLP HTTP, disabled without endpoint), `withSpan()`, `currentTraceId()`.
- `src/opbx/errors.ts` — OpbxError + `normalizeOpbxError()` mapping all upstream shapes (422 Laravel, 401/403 flat, structured `{error:{code}}`, 500 DELETE_ERROR→resource_in_use, message-only campaign 409s).
- `src/opbx/client.ts` — typed fetch client (`call<OpId>(method, path, operationId, {pathParams, query, body})` over generated `operations` map; timeout via AbortSignal; credential never logged).
- `src/opbx/generated/schema.d.ts` — openapi-typescript output (regenerate: `npm run generate:opbx-client`; script resolves modular $refs).
- `src/server/auth.ts` — IdentityResolver (PAT→/auth/me, opbxk_→minimal identity, TTL cache).
- `src/server/context.ts` — McpRequestContext {identity, opbx, requestId, traceId}.
- `src/server/mcp.ts` — per-request McpServer factory; tool wrapper enforces RBAC→rate-limit→confirmation→execute→error-map; structured success/error results.
- `src/server/http.ts` — Fastify: /health, /ready (probes /api/health), /mcp POST|GET|DELETE stateless (`sessionIdGenerator: undefined`, reply.hijack()), edge rate limit.
- `src/security/` — permissions.ts (5 roles, identityHasAnyRole), tool-policy.ts (ToolPolicy + annotationsFor + READ/WRITE/DESTRUCTIVE presets), rate-limiter.ts (per-identity/class sliding window, in-memory).
- `src/tools/` — registry.ts (self-registering defineTool; ToolDefinition carries optional `operation` ref for contract tests), factory.ts (defineListTool with pagination+filters+pathParams.map, defineGetTool, defineRawReadTool), index.ts barrel, group files: organization, extensions, phone-numbers, ring-groups, ivr, business-hours, conference-rooms, ai, calls (CDR search w/ controller-verified filter superset), active-calls, campaigns, distribution-lists, security-lists, users, reporting (recordings + call-tracking reads).
- `src/opbx/services/read-service.ts` — listResource/getResource/rawGet over the typed client.
- `src/opbx/transformers/pagination.ts` — normalizeList ({data,meta}→{items,pagination}), unwrapData.
- `tests/contract/spec-manifest.test.ts` — parses the real modular spec (yaml pkg), asserts every tool's operationId/method/path exists and placeholders match; the API-drift tripwire (`npm run validate:opbx-api`). NOTE: generated types last-win duplicate paths, so `getCdrStatistics`/`getCampaignCallerIdStats` are the usable operationIds.
- 74 tools registered (55 reads + writes; count drifts — tests assert uniqueness + >50, not exact).
- `defineWriteTool` factory: WRITE_POLICY default (owner|pbx_admin), mapArgs → {pathParams, body}, unwraps {data}.
- Client details: query arrays serialize as `key[]=` (Laravel style). 2026-09-08: the opbxk_ 401 special case was REMOVED — keys now cover all business route groups (see api-keys.md); upstream 403 scope denials carry precise messages.

## Phase 4 source-validated write semantics (FormRequests, not spec)

- **Ring group spec is WRONG**: strategies are simultaneous|round_robin|sequential (spec says priority/weighted/memory); fallback actions are extension|ring_group|ivr_menu|ai_assistant|ai_load_balancer|hangup (spec says voicemail/disconnect); members required 1-50 with priority 1-100; timeout 5-300; update = FULL replacement (all fields required). Minor upstream bug: fallback_ai_assistant_id validates exists:extensions,id.
- **Business hours**: open/closed actions use prefixed string target_id (ext-N, rg-N, conf-N, ivr-N ONLY — enum lists ai_assistant/ai_load_balancer but parseTargetId() can't parse them); all 7 days required; exceptions {date Y-m-d, name, type: closed|special_hours, time_ranges}; update deletes+recreates exceptions.
- **IVR**: prompt = exactly one of tts_text(+tts_voice)/recording_id/audio_file_path; options 1-20 {input_digits, destination_type (7, no hangup), destination_id, priority 1-20}; failover allows hangup; update = full replacement.
- **Extension**: number regex ^\d{3,5}$ unique per org; 8 types w/ conditional configuration keys (conference_room_id, ring_group_id, ivr_id, ai_assistant_id, ai_load_balancer_id, container_application_name+container_block_name, forward_to); update PUT accepts partial.
- **Phone number**: E.164 strict unless enable_non_e164; immutable after create; routing_config key must match routing_type; create is LOCAL record only (no Cloudonix provisioning).
- **Campaign**: create requires name/routing_destination_type/dial_timeout 1-300/destination_connect/caller_id E.164/max_dial_attempts 1-5/concurrent_active_calls 1-50/schedule(7 days w/ time_ranges {id,start,end})/start_date≥today/end_date/timezone; caller_id_pool[{did_id,weight}] + caller_id_strategy (round_robin|random|least_recently_used); AMD actions HANGUP|CONTINUE; update = partial (sometimes) but caller_id_pool blocked while ACTIVE.
- **Distribution lists**: create {name, description}; batch add ≤1000 {phone_number,name?} validated by libphonenumber (region default US — fictional test numbers must use NXX-555-01XX); assign requires list status ready (policy-gated, 403 otherwise); unassign only when all destinations pending with zero attempts (422).
- **Blacklist**: caller_id_pattern regex ^[\d+*?]+$, match_type exact|prefix|wildcard, rejection drop|reject|torment, is_global XOR did_number_ids[]. **Whitelist**: name + destination_country (unique per org) + destination_prefix + outbound_trunk_name (required) + default_caller_id_did_id.
- **Users**: create requires password (8+ mixed+numbers), role any of 5; update PUT partial; role→owner changes owner-only upstream; last-owner delete blocked upstream (409 LAST_OWNER_DELETE_BLOCKED).
- **Conference rooms**: name unique, max_participants 2-1000, pin/host_pin digits ≤20.
- `tests/unit/` — errors, config, auth, security, http (30 tests incl. full JSON-RPC initialize+tools/call round-trip via app.inject; responses may be SSE frames — parse `data:` lines).
- `Dockerfile` (multi-stage, non-root, wget healthcheck), `docker-compose.yml` (+ optional `telemetry` profile w/ OTel collector), `.env.example`, `eslint.config.mjs` (flat).

## Verified

- `npm run typecheck|lint|test|build` all pass.
- Live test vs local OPBX stack: initialize/tools-list/tools-call(get_organization) with real PAT; bad token → 401 JSON-RPC error; Docker image boots and /ready reaches host OPBX.
- Dev DB has test owner `mcp-test@example.com` (org 1 "Default Organization") created via tinker for live tests.

## Source-validated facts the MCP design depends on

- Tenant is implicit from token (`OrganizationScope` reads `auth()->user()->organization_id`); one user = one org.
- PATs expire in 24h and are revoked on any login/refresh/password change; `opbxk_` keys never expire, per-resource read/write scoped, deny-by-default (`EnforceApiKeyScope`), cannot call `/auth/me` or `/profile`. As of 2026-09-08 keys cover ALL business route groups (23 grantable resources incl. campaigns, session-updates, call-notifications).
- Roles: owner, pbx_admin, pbx_user, reporter, supervisor. Policies use role helpers; Sanctum abilities exist but are never enforced.
- **IVR mutations and business-hours/IVR `toggle-status` have NO authorization in OPBX** — MCP must enforce its own RBAC.
- In-use deletes return HTTP 500 `DELETE_ERROR` (not 409) for ring groups/conference rooms/business hours/ALBs (`AbstractApiCrudController::destroy` swallows `ResourceInUseException`); IVR menus return proper 409.
- AI assistant delete checks extension refs only; ALB delete reference check always passes; extension delete has no ref check.
- Campaign lifecycle: DRAFT|ACTIVE|PAUSED|COMPLETED|ARCHIVED; start allowed from DRAFT **or PAUSED**; errors are message-only (no machine codes); disconnect-call is owner-only; coaching = `POST /session-updates/{id}/coach-target` (spy|whisper|barge, owner|supervisor) returns a dial destination, no stop endpoint.
- DID routing lives on `PUT /v1/phone-numbers/{id}` (`routing_type` + `routing_config`); 7 types, targets must be active.
- Spec duplicates: CDR export/statistics, campaign caller-id-stats, reset-caller-id-cycle are each defined twice; `GET /v1/call-detail-records/{id}/recording` exists in routes but is missing from the spec; two distinct `ValidationError` schemas exist (generator renames one to `ValidationError-2`).

## Next steps

Phase 3: read-only tool groups (extensions, phone-numbers, ring-groups, IVR, business-hours, conference-rooms, AI assistants/providers/ALBs reads, CDRs, active calls, campaign/list reads) + pagination normalizer + per-group contract tests.

## What it is

`opbx-mcp`: a standalone, stateless MCP server (Node 24 + TypeScript + Fastify + official MCP TS SDK + Zod v4 + OTel + Pino + Vitest) exposing a curated semantic tool surface over the OPBX REST API. Lives in `mcp-server/` inside this repo (branch `feature/mcp-server`). Never talks to MySQL/Redis directly; REST only.

## Key decisions (user-approved)

- **Location**: `mcp-server/` subdirectory of this repo.
- **Auth**: pass-through of the client's OPBX credential. MCP accepts both Sanctum PATs (validate via `GET /api/v1/auth/me` → user/org/role/is_platform_manager) and `opbxk_` scoped API keys (no identity echo endpoint exists; org is implicit). Server keeps an `AuthenticatedIdentity`/`McpRequestContext` abstraction so the provider is swappable. Never accepts `organization_id` from tool args; never sends `X-Operate-As-Organization`.
- **Delivery**: phase-gated. Stop after Phase 1 inventory for user review (current state).

## Files

- `mcp-server/docs/opbx-api-inventory.md` — all 267 OpenAPI operations classified (MCP_TOOL / MCP_RESOURCE / INTERNAL_NOT_EXPOSED / PLATFORM_ADMIN_ONLY / AUTH_INFRASTRUCTURE / EXECUTION_PLANE) with risk/role/destructive/reason, discovery findings, discrepancy list, proposed v1 catalog (~50 tools), 16 resource templates, 4 prompts.
- Generation scripts: `mcp-server/scripts/generate-opbx-client.mjs` (client types); OpenAPI inventory extractor/generator remain in `/var/folders/.../T/opencode/` (extract_openapi.py, gen_inventory.py — copy into repo if regeneration is needed).

## Source-validated facts the MCP design depends on

- Tenant is implicit from token (`OrganizationScope` reads `auth()->user()->organization_id`); one user = one org.
- PATs expire in 24h and are revoked on any login/refresh/password change; `opbxk_` keys never expire, per-resource read/write scoped, deny-by-default (`EnforceApiKeyScope`), cannot call `/auth/me` or `/profile`. As of 2026-09-08 keys cover ALL business route groups (23 grantable resources incl. campaigns, session-updates, call-notifications).
- Roles: owner, pbx_admin, pbx_user, reporter, supervisor. Policies use role helpers; Sanctum abilities exist but are never enforced.
- **IVR mutations and business-hours/IVR `toggle-status` have NO authorization in OPBX** — MCP must enforce its own RBAC.
- In-use deletes return HTTP 500 `DELETE_ERROR` (not 409) for ring groups/conference rooms/business hours/ALBs (`AbstractApiCrudController::destroy` swallows `ResourceInUseException`); IVR menus return proper 409.
- AI assistant delete checks extension refs only; ALB delete reference check always passes; extension delete has no ref check.
- Campaign lifecycle: DRAFT|ACTIVE|PAUSED|COMPLETED|ARCHIVED; start allowed from DRAFT **or PAUSED**; errors are message-only (no machine codes); disconnect-call is owner-only; coaching = `POST /session-updates/{id}/coach-target` (spy|whisper|barge, owner|supervisor) returns a dial destination, no stop endpoint.
- DID routing lives on `PUT /v1/phone-numbers/{id}` (`routing_type` + `routing_config`); 7 types, targets must be active.
- Spec duplicates: CDR export/statistics, campaign caller-id-stats, reset-caller-id-cycle are each defined twice; `GET /v1/call-detail-records/{id}/recording` exists in routes but is missing from the spec.

## Next steps

Phase 2 (after user approves inventory): scaffold `mcp-server/` (Fastify + MCP SDK streamable HTTP stateless, auth layer, OPBX typed client generated from the spec, error normalization, OTel/Pino, Docker). Then read-only tools (Phase 3), mutations (4), high-risk + confirmations (5), semantic tools (6), resources/prompts (7), validation (8), docs/CI (9).
