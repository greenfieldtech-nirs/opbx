# OPBX REST API Inventory (MCP Classification)

> Generated from `docs/opbx-openapi/openapi.yaml` + `docs/opbx-openapi/paths/**` and validated against Laravel source (controllers, policies, FormRequests, routes).
> Classifications are curated and hand-maintained. To re-audit the operation surface after OPBX API changes, run `python3 mcp-server/scripts/extract-openapi-operations.py` from the repo root and diff against this document.

## Summary

- Total operations: **267** (all have `operationId`)
- MCP tools: **104**
- MCP resources (entity reads): **16**
- internal / not exposed: **91**
- platform-admin only: **16**
- auth infrastructure: **16**
- execution plane: **24**
- Distinct proposed MCP tools: **92**
- MCP resource types: **16**

### Classification legend

| Classification | Meaning |
|---|---|
| MCP_TOOL | Exposed as a curated MCP tool (possibly merged/renamed) |
| MCP_RESOURCE | Single-entity read exposed as MCP resource template (also wrapped by a `get_*` tool) |
| INTERNAL_NOT_EXPOSED | Tenant-plane but intentionally not in the agent surface (secrets, files, niche, deferred) |
| PLATFORM_ADMIN_ONLY | Cross-tenant platform-manager API; isolated for a future privileged MCP server |
| AUTH_INFRASTRUCTURE | Login/session/invite/broadcasting plumbing; not business tools |
| EXECUTION_PLANE | Cloudonix-facing runtime (CXML voice routing, webhooks, workers); must never be agent-callable |

### Role shorthand

- `any` = any authenticated org user (supervisors see scoped subsets where noted)
- `cfg` = owner or pbx_admin
- `owner` = owner only
- `pm` = platform manager (cross-tenant)
- `NONE in OPBX` = endpoint has **no authorization** in OPBX source; MCP enforces its own RBAC

---

## Discovery findings (validated against source)

### Tenant model
- One user belongs to exactly **one** organization (`users.organization_id`; no pivot table exists).
- Tenant is **implicit from the token** — `OrganizationScope` reads `auth()->user()->organization_id` (`app/Scopes/OrganizationScope.php:76-91`). No header/URL param can select a tenant. This makes "never trust model-supplied tenant IDs" structural: the MCP server simply never sends one.
- Exception: platform managers may impersonate via `X-Operate-As-Organization` (`ApplyOperateAsOrganization`). The tenant MCP server **never** sends this header.
- Suspended/deleted orgs are blocked per-request with 403 (`EnsureTenantScope`).

### Authentication model (validated)
Two credential types exist:

| Credential | Format | Identity echo | Lifecycle |
|---|---|---|---|
| Sanctum PAT | `id\|token` | `GET /api/v1/auth/me` returns `{user:{id, organization_id, role, status, is_platform_manager, organization:{...}}}` | **24h expiry; revoked on any login/refresh/password change** |
| Scoped API key | `opbxk_…` | **none** (keys cannot call `/me` or `/profile`) | Never expires; per-resource read/write grants; deny-by-default (`EnforceApiKeyScope`) |

Design implication: PAT pass-through works but is short-lived. The MCP auth layer should accept **both**, use `/auth/me` for PAT identity resolution, and for `opbxk_` keys derive org/role context from OPBX responses (keys are implicitly org-scoped; RBAC is already enforced by `EnforceApiKeyScope`). Sanctum token *abilities* exist but are **never enforced** — authorization is 100% role/policy-based.

### RBAC model (validated)
- Roles: `owner`, `pbx_admin`, `pbx_user`, `reporter`, `supervisor` (`app/Enums/UserRole.php`).
- No permission package; authorization = policies using role helpers (`isOwner()`, `isPBXAdmin()`, `canManageUsers()`, `canManageConfiguration()`, `canManageOrganization()`).
- Typical split: reads = any role (supervisors scoped to assigned resources); config writes = owner|pbx_admin; org-critical (disconnect call, Cloudonix settings, archive campaign) = owner only.
- **MCP must enforce its own RBAC** on top: three endpoints have no authorization in OPBX (see discrepancies), and tool-list filtering is not a security boundary.

### Execution plane (excluded, 24 ops)
`/voice/route`, `/voice/ivr-input`, `/voice/amd-action`, `/callbacks/voice/*`, `/webhooks/cloudonix/*`, `/webhooks/auto-dialer/*`, `/v1/dialer/worker/*` (11 ops, incl. `initiateCall` which originates calls), `/storage/recordings/{path}` (public HMAC), `/v1/call-tracking-dni/swap` (public). Auth is per-channel secrets (webhook HMAC/Bearer, worker tokens), not user tokens — structurally unreachable by tenant credentials anyway.

### Platform-manager surface (isolated, 16 ops)
`/v1/platform/*` behind `platform.manager` middleware + `bypass.organization.scope`. Includes org CRUD, cross-tenant user management, and operate-as impersonation. Reserved for a future **separate** privileged MCP server; zero exposure in the tenant catalog.

### Error formats (MCP must normalize — inconsistent upstream)
- 422 validation: `{"message", "errors":{field:[...]}}` (stock Laravel)
- 403: `{"error":"Forbidden","message":…}`; 401: `{"error":"Unauthenticated",…}`
- Campaign lifecycle errors: bare `{"message":…}` with **no machine-readable codes** (409 wrong-state, 422 no-ready-list)
- Structured errors via `HandlesApiErrors`: `{"error":{"code", "message", "details"}}`
- Cross-tenant access masked as 404 (deliberate)

### Pagination
List endpoints return `{data: [...], meta: PaginationMeta}`; MCP normalizes to `{items, pagination:{page, per_page, total, last_page}}`.

## OpenAPI / source discrepancies (must-fix or document)

1. **Duplicate path definitions in the spec** — 4 paths defined twice with different operationIds (stale files):
   - `GET /v1/call-detail-records/export` (`exportCallDetailRecords` in `export.yaml`, `exportCdr` in `index.yaml`)
   - `GET /v1/call-detail-records/statistics` (`callDetailRecordStatistics` / `getCdrStatistics`)
   - `GET /v1/auto-dialer-campaigns/{campaign}/caller-id-stats` (`getCallerIdStats` / `getCampaignCallerIdStats`)
   - `POST /v1/auto-dialer-campaigns/{campaign}/reset-caller-id-cycle` (`resetCallerIdCycle` / `resetCampaignCallerIdCycle`)
2. **Missing from spec**: `GET /api/v1/call-detail-records/{id}/recording` exists in `routes/api.php:469` (streams call audio) but has no OpenAPI entry.
3. **No authorization on IVR mutations**: `IvrMenuController` has no policy; `StoreIvrMenuRequest::authorize()` returns `true` (`app/Http/Requests/StoreIvrMenuRequest.php:20-23`). Any authenticated org role can create/update/delete IVR menus.
4. **No authorization on status toggles**: `business-hours/{id}/toggle-status` and `ivr-menus/{id}/toggle-status` check only tenant match — any role can flip **live call routing** (`BusinessHoursController.php:475-485`, `IvrMenuController` toggleStatus).
5. **In-use deletes return HTTP 500, not 409**: `AbstractApiCrudController::destroy()` catches `ResourceInUseException` in a generic `\Exception` handler → `{error:{code:"DELETE_ERROR"}}` for ring groups, conference rooms, business hours, AI load balancers. Only IVR menus return the intended 409 (`AbstractApiCrudController.php:913-963`).
6. **AI assistant delete reference-check gap**: in-use check covers extensions only; DID/IVR/campaign references are not checked (`AiAssistantController.php:147-179`). ALB delete reference check has no mapping at all and always passes.
7. **Extension delete has no reference check**: DIDs/IVR options pointing at a deleted extension dangle.
8. **Campaign start does not revalidate destination**: an assistant deleted after campaign creation is not blocked at start; only status (DRAFT/PAUSED) + ready-list are checked (`CampaignLifecycleService.php:34-48`).
9. **Business-hours exception `type` silently dropped** (`BusinessHoursException::$fillable` lacks the fields the duplicate/create paths pass).
10. **Memory-file drift** (project-internal): `auto-dialer-campaigns.md` cites wrong controller path/service name; `multi-tenancy.md` omits the supervisor role and operate-as; `live-calls.md`, `voice-routing-engine.md`, `outbound-whitelist.md` are referenced but missing.
11. **`listUsers` role filter enum omits `supervisor`** (`paths/users/index.yaml`) although the role exists in `UserRole` enum — filtering users by supervisor role is impossible via the documented API.
12. **`listCallDetailRecords` spec documents only `from/to/extension_id`**, but `CallDetailRecordController::getAllowedFilters()` supports `from, to, disposition, from_date, to_date, user, direction` (+ more sort fields). `search_calls` exposes the controller-verified superset.
13. **`GET /v1/session-updates/active` envelope is `{data, meta}` where meta is a stats block** (`total_active_calls`, `by_status`, `by_direction`), not `PaginationMeta` — it is not a paginated endpoint (hard cap 100). MCP normalizes to `{items, summary}`.
14. **Two distinct `ValidationError` component schemas exist** in the spec; type generation renames one to `ValidationError-2` (openapi-typescript warning).
15. **Campaign, session-update and call-notification route groups are Sanctum-only** — scoped `opbxk_` API keys cannot call them at all (bare 401, not a scope 403). Grantable resources for keys are the 12 in `GrantableResource`; campaigns and live calls are not among them. MCP maps this to an actionable authentication_error message.
16. **Ring-group spec is badly stale** (`paths/ring-groups`): spec strategies `priority|weighted|memory` do not exist (`RingGroupStrategy`: `simultaneous|round_robin|sequential`); spec fallback actions `voicemail|disconnect` do not exist (`RingGroupFallbackAction`: `...|hangup`); `updateRingGroup` request body in the spec is actually the *response* schema (requires `id`/`organization_id`, embeds nested Extension objects in members). Real update = full replacement: name/strategy/timeout(5-300)/ring_turns(1-9)/fallback_action/status/members(1-50, priority 1-100) all required. MCP follows the FormRequests.
17. **Business-hours action types wider than parser**: `BusinessHoursActionType` includes `ai_assistant|ai_load_balancer`, but `BusinessHoursSchedule::parseTargetId()` only parses `ext-|rg-|conf-|ivr-` prefixes — AI targets are unusable in open/closed actions. MCP restricts to the 4 parseable types.
18. **Minor upstream validation bug**: `fallback_ai_assistant_id` on ring groups validates `exists:extensions,id` instead of `exists:ai_assistants,id` (StoreRingGroupRequest / UpdateRingGroupRequest).
19. **Campaign/list destinations phone validation is libphonenumber-based** (default region US), stricter than E.164 regex used elsewhere — fictional/test numbers must come from the NXX-555-01XX range.
20. **`assignListToCampaign` silently ACTIVATES draft campaigns** (`ListManagementService.php:569-573`): assigning a ready list to a draft campaign sets it ACTIVE without `started_at`, CAC reset, or cache busting (unlike the proper `CampaignLifecycleService::start()`). This also means the scheduler's auto-start flag is not the only activation path. MCP gates `assign_distribution_list` behind confirmation with an explicit warning. Additionally, lifecycle state preconditions are enforced at the POLICY level (`AutoDialerCampaignPolicy::start` calls `canStart()`), so wrong-state transitions surface as 403 "This action is unauthorized." rather than the service's 409/422 — tool descriptions document this.
21. **Session endpoints 500 with PHP internals on non-numeric session IDs**: `SessionUpdateController::{getSessionDetails,disconnectSession,coachTarget}(int $sessionId)` throw an uncaught TypeError (response body leaks class names and `/var/www/html` paths) when given a non-numeric id. MCP constrains session_id args to numeric, and normalizeOpbxError now sanitizes ALL upstream 5xx bodies to a generic message.
22. **Ring-group detail responses lack member counts**: `GET /ring-groups/{id}` includes the `members` array but not `members_count`/`active_members_count` (list response has both). Composite tooling falls back to `members.length`.

### Implemented semantic composite tools
- `configure_phone_number_routing(phone_number_id, destination_type, destination_id)` — validates the target exists + is active (ring groups must have members) via the corresponding read op, then applies `updatePhoneNumber`. Pre-validation failures return actionable validation_error before any write.
- `validate_configuration()` — read-only cross-resource audit (DIDs, ring groups, IVR, business hours, extensions, AI assistants/ALBs, campaigns/lists, whitelist) emitting structured findings `{severity, code, resource_type, resource_id, message, suggested_action}` sorted error→warning→info, with per-collection degradation if a read fails.
- `configure_ai_call_routing` was intentionally NOT built: it is `configure_phone_number_routing` with destination_type=ai_assistant/ai_load_balancer (YAGNI; documented).

## Proposed v1 tool catalog (~50 tools)

Curated subset of the 92 proposed tools — the smallest coherent surface for safe agent control:

- **Extensions** (5): list, get, create, update, delete
- **Phone numbers** (4 + 1 composite): list, get, update (= routing), delete, `configure_phone_number_routing`
- **Ring groups** (5): CRUD
- **IVR** (7): list, get, create, update, delete, set_status, list_voices
- **Business hours** (7): list, get, create, update, delete, duplicate, set_status
- **Conference rooms** (5): CRUD
- **AI** (8): list_ai_providers, AI assistant CRUD (5), list/get AI load balancers (2)
- **Campaigns** (10): list, get, create, update, delete, start, pause, resume, archive, get_campaign_status
- **Distribution lists** (7): list, get, create, assign, unassign, add_destinations (JSON batch), validation_errors
- **Calls & analytics** (6): search_calls, get_call_details, get_call_statistics, list_active_calls, get_active_call, get_active_call_statistics
- **Live-call actions** (2): disconnect_call, start_call_coaching
- **Security lists** (6): list/block/unblock inbound blacklist, list/add/remove outbound whitelist
- **Users** (4): list, get, invite, update
- **Meta** (2): validate_configuration, whoami-backed organization resource

Deferred to v2: call-tracking writes, notification settings, recordings upload/delete, ALB create/update/delete (read-only in v1), supervisor assignment management, CSV-oriented list operations, dial resets.

### Composite / semantic tools (multi-op orchestration)
- `configure_phone_number_routing(did, destination_type, destination_id)` → validates target existence via reads, then `updatePhoneNumber`. Business-hours fallback composition lives inside the schedule resource itself.
- `validate_configuration` → read-only cross-resource audit: dangling DID routes, inactive targets, ring groups without active members, IVR options to missing targets, campaigns without ready lists / with inactive destinations, ALBs referencing inactive assistants, assistants referenced by deleted resources. All checks are implementable from exposed REST data.

### High-risk operations (confirmation required)
`delete_extension`, `delete_phone_number`, `delete_ring_group`, `delete_ivr_menu`, `delete_business_hours`, `delete_conference_room`, `delete_ai_assistant`, `delete_campaign`, `delete_distribution_list`, `start_campaign`, `pause_campaign`, `resume_campaign`, `archive_campaign`, `disconnect_call`, `start_call_coaching`, `unblock_inbound_number`, `remove_outbound_whitelist_rule`, `delete_user`.

### MCP resources (16 templates)
`opbx://organization`, `opbx://extensions/{id}`, `opbx://phone-numbers/{id}`, `opbx://ring-groups/{id}`, `opbx://ivr-menus/{id}`, `opbx://business-hours/{id}`, `opbx://conference-rooms/{id}`, `opbx://ai-assistants/{id}`, `opbx://ai-load-balancers/{id}`, `opbx://campaigns/{id}`, `opbx://distribution-lists/{id}`, `opbx://call-detail-records/{id}`, `opbx://recordings/{id}`, `opbx://users/{id}`, `opbx://inbound-blacklist/{id}`, `opbx://outbound-whitelist/{id}`

### MCP prompts (planned)
`configure_pbx`, `build_inbound_call_flow`, `create_outbound_campaign`, `diagnose_call_problem` — each guides the agent to the relevant tools/resources above; no secrets embedded.

---

## Per-endpoint classification (all 267 operations)

## Extensions

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/extensions` | `listExtensions` | MCP_TOOL | list_extensions | low | any (supervisor scoped) | no | Paginated list with filters (type/status/search/sort). |
| POST | `/v1/extensions` | `createExtension` | MCP_TOOL | create_extension | medium | cfg | no | Creates extension; user-type extensions sync a Cloudonix subscriber. |
| POST | `/v1/extensions/sync` | `syncExtensions` | INTERNAL_NOT_EXPOSED | - | high | cfg | no | Bulk Cloudonix subscriber sync; platform provisioning utility. Future admin tool candidate. |
| GET | `/v1/extensions/sync/compare` | `compareSyncStatus` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Read-only sync diff. Excluded v1 together with syncExtensions; future candidate. |
| DELETE | `/v1/extensions/{extension}` | `deleteExtension` | MCP_TOOL | delete_extension | high | cfg | yes | Hard delete. Side effect: deletes Cloudonix subscriber for user-type. No reference check - DIDs/IVR may dangle. Confirmation required. |
| GET | `/v1/extensions/{extension}` | `getExtension` | MCP_RESOURCE | opbx://extensions/{id} (+ get_extension tool) | low | any (supervisor scoped) | no | Single entity read; exposed as MCP resource and get tool. |
| PATCH | `/v1/extensions/{extension}` | `patchExtension` | MCP_TOOL | (merged into update_extension) | medium | cfg (+pbx_user: own) | no | PATCH not exposed separately; consolidated per task rule 7. |
| PUT | `/v1/extensions/{extension}` | `updateExtension` | MCP_TOOL | update_extension | medium | cfg (+pbx_user: own ext only) | no | Canonical update. MCP exposes one update semantic only. |
| GET | `/v1/extensions/{extension}/password` | `getExtensionPassword` | INTERNAL_NOT_EXPOSED | - | high | cfg | no | Returns SIP credentials. Secrets must never flow through MCP responses. |
| PUT | `/v1/extensions/{extension}/reset-password` | `resetExtensionPassword` | INTERNAL_NOT_EXPOSED | - | high | cfg | yes | Rotates SIP credentials. Excluded v1 (credential management); future candidate. |

## Phone Numbers / DIDs

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/phone-numbers` | `listPhoneNumbers` | MCP_TOOL | list_phone_numbers | low | any | no | DID list. |
| POST | `/v1/phone-numbers` | `createPhoneNumber` | MCP_TOOL | create_phone_number | medium | cfg | no | Registers a local DID record (no Cloudonix provisioning). |
| GET | `/v1/phone-numbers/default-caller-id` | `getDefaultCallerId` | INTERNAL_NOT_EXPOSED | - | low | any | no | Deprecated endpoint. |
| PUT | `/v1/phone-numbers/default-caller-id` | `setDefaultCallerId` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deprecated endpoint. |
| DELETE | `/v1/phone-numbers/{phone_number}` | `deletePhoneNumber` | MCP_TOOL | delete_phone_number | high | cfg | yes | Hard delete, local record only (no Cloudonix release call, no reference check). Confirmation required. |
| GET | `/v1/phone-numbers/{phone_number}` | `getPhoneNumber` | MCP_RESOURCE | opbx://phone-numbers/{id} (+ get_phone_number) | low | any | no | Single DID incl. routing_type/routing_config. |
| PATCH | `/v1/phone-numbers/{phone_number}` | `patchPhoneNumber` | MCP_TOOL | (merged into update_phone_number) | medium | cfg | no | Consolidated. |
| PUT | `/v1/phone-numbers/{phone_number}` | `updatePhoneNumber` | MCP_TOOL | update_phone_number (+ backs configure_phone_number_routing) | medium | cfg | no | routing_type + routing_config live on this update. Cross-field validation requires active target; ring groups need >=1 active member. Live-call impact. |

## Ring Groups

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/ring-groups` | `listRingGroups` | MCP_TOOL | list_ring_groups | low | any (supervisor scoped) | no |  |
| POST | `/v1/ring-groups` | `createRingGroup` | MCP_TOOL | create_ring_group | medium | cfg | no |  |
| DELETE | `/v1/ring-groups/{ring_group}` | `deleteRingGroup` | MCP_TOOL | delete_ring_group | high | cfg | yes | Hard delete. In-use guard exists but returns HTTP 500 DELETE_ERROR (ResourceInUseException swallowed) - MCP must map. Confirmation required. |
| GET | `/v1/ring-groups/{ring_group}` | `getRingGroup` | MCP_RESOURCE | opbx://ring-groups/{id} (+ get_ring_group) | low | any (supervisor scoped) | no |  |
| PATCH | `/v1/ring-groups/{ring_group}` | `patchRingGroup` | MCP_TOOL | (merged into update_ring_group) | medium | cfg | no | Consolidated. |
| PUT | `/v1/ring-groups/{ring_group}` | `updateRingGroup` | MCP_TOOL | update_ring_group | medium | cfg | no |  |

## IVR Menus

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/ivr-menus` | `listIVRMenus` | MCP_TOOL | list_ivr_menus | low | any | no |  |
| POST | `/v1/ivr-menus` | `createIVRMenu` | MCP_TOOL | create_ivr_menu | medium | NONE in OPBX - MCP enforces cfg | no | No policy, FormRequest authorize()=true. OPBX allows any role; MCP must impose its own RBAC. |
| GET | `/v1/ivr-menus/voices` | `getIvrVoices` | MCP_TOOL | list_ivr_voices | low | any | no | Available TTS voices for IVR prompts. |
| DELETE | `/v1/ivr-menus/{ivrMenu}` | `deleteIVRMenu` | MCP_TOOL | delete_ivr_menu | high | NONE in OPBX - MCP enforces cfg | yes | Hard delete; proper 409 with references when in use (only resource with correct in-use semantics). Confirmation required. |
| GET | `/v1/ivr-menus/{ivrMenu}` | `getIVRMenu` | MCP_RESOURCE | opbx://ivr-menus/{id} (+ get_ivr_menu) | low | any | no |  |
| PUT | `/v1/ivr-menus/{ivrMenu}` | `updateIVRMenu` | MCP_TOOL | update_ivr_menu | medium | NONE in OPBX - MCP enforces cfg | no | Same authorization gap as create. |
| PATCH | `/v1/ivr-menus/{ivrMenu}/toggle-status` | `toggleIvrMenuStatus` | MCP_TOOL | set_ivr_menu_status | medium | NONE in OPBX - MCP enforces cfg | no | No authorization in OPBX; flips live routing immediately. MCP enforces cfg role. |

## Business Hours

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/business-hours` | `listBusinessHoursSchedules` | MCP_TOOL | list_business_hours | low | any | no |  |
| POST | `/v1/business-hours` | `createBusinessHoursSchedule` | MCP_TOOL | create_business_hours | medium | cfg | no | Exceptions managed inline; note: exception `type` field is silently dropped by OPBX (known bug). |
| POST | `/v1/business-hours/{businessHour}/duplicate` | `duplicateBusinessHours` | MCP_TOOL | duplicate_business_hours | low | cfg | no | Creates inactive '(Copy)' with deep-copied schedule. Exception details partially dropped (known bug). |
| PATCH | `/v1/business-hours/{businessHour}/toggle-status` | `toggleBusinessHoursStatus` | MCP_TOOL | set_business_hours_status | medium | NONE in OPBX - MCP enforces cfg | no | No authorization in OPBX (any role); affects live routing. MCP enforces cfg role. |
| DELETE | `/v1/business-hours/{business_hour}` | `deleteBusinessHoursSchedule` | MCP_TOOL | delete_business_hours | high | cfg | yes | Soft delete; in-use guard affected by 500 DELETE_ERROR swallow bug. Confirmation required. |
| GET | `/v1/business-hours/{business_hour}` | `getBusinessHoursSchedule` | MCP_RESOURCE | opbx://business-hours/{id} (+ get_business_hours) | low | any | no | Includes days, time ranges, exceptions, open/closed actions. |
| PATCH | `/v1/business-hours/{business_hour}` | `patchBusinessHoursSchedule` | MCP_TOOL | (merged into update_business_hours) | medium | cfg | no | Consolidated. |
| PUT | `/v1/business-hours/{business_hour}` | `updateBusinessHoursSchedule` | MCP_TOOL | update_business_hours | medium | cfg | no | Exceptions are delete-and-recreate on every update. |

## Conference Rooms

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/conference-rooms` | `listConferenceRooms` | MCP_TOOL | list_conference_rooms | low | any | no |  |
| POST | `/v1/conference-rooms` | `createConferenceRoom` | MCP_TOOL | create_conference_room | medium | cfg | no |  |
| DELETE | `/v1/conference-rooms/{conference_room}` | `deleteConferenceRoom` | MCP_TOOL | delete_conference_room | high | cfg | yes | Hard delete; in-use guard affected by 500 DELETE_ERROR swallow bug. Confirmation required. |
| GET | `/v1/conference-rooms/{conference_room}` | `getConferenceRoom` | MCP_RESOURCE | opbx://conference-rooms/{id} (+ get_conference_room) | low | any | no |  |
| PATCH | `/v1/conference-rooms/{conference_room}` | `patchConferenceRoom` | MCP_TOOL | (merged into update_conference_room) | medium | cfg | no | Consolidated. |
| PUT | `/v1/conference-rooms/{conference_room}` | `updateConferenceRoom` | MCP_TOOL | update_conference_room | medium | cfg | no |  |

## AI Assistants

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/ai-assistant/providers` | `listAiAssistantProviders` | MCP_TOOL | list_ai_providers | low | any | no | Provider registry (protocols, capabilities). |
| GET | `/v1/ai-assistant/providers/protocol/{protocol}` | `getProvidersByProtocol` | MCP_TOOL | (merged into list_ai_providers protocol filter) | low | any | no | One semantic tool covers all three provider reads. |
| GET | `/v1/ai-assistant/providers/{provider}` | `getAiAssistantProvider` | MCP_TOOL | get_ai_provider | low | any | no | Single provider details. |
| GET | `/v1/ai-assistants` | `listAiAssistants` | MCP_TOOL | list_ai_assistants | low | any | no |  |
| POST | `/v1/ai-assistants` | `createAiAssistant` | MCP_TOOL | create_ai_assistant | medium | cfg | no |  |
| DELETE | `/v1/ai-assistants/{ai_assistant}` | `deleteAiAssistant` | MCP_TOOL | delete_ai_assistant | high | cfg | yes | Soft delete; in-use check covers extensions only - DID/IVR/campaign references NOT checked by OPBX (documented gap). Confirmation required. |
| GET | `/v1/ai-assistants/{ai_assistant}` | `getAiAssistant` | MCP_RESOURCE | opbx://ai-assistants/{id} (+ get_ai_assistant) | low | any | no | Provider credentials must be redacted from MCP output. |
| PATCH | `/v1/ai-assistants/{ai_assistant}` | `patchAiAssistant` | MCP_TOOL | (merged into update_ai_assistant) | medium | cfg | no | Consolidated. |
| PUT | `/v1/ai-assistants/{ai_assistant}` | `updateAiAssistant` | MCP_TOOL | update_ai_assistant | medium | cfg | no |  |

## AI Load Balancers

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/ai-assistant-load-balancers` | `listAiLoadBalancers` | MCP_TOOL | list_ai_load_balancers | low | any | no |  |
| POST | `/v1/ai-assistant-load-balancers` | `createAiLoadBalancer` | MCP_TOOL | create_ai_load_balancer | medium | cfg | no |  |
| DELETE | `/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | `deleteAiLoadBalancer` | MCP_TOOL | delete_ai_load_balancer | high | cfg | yes | Soft delete; reference check has NO mapping for this type so always passes (documented gap). Confirmation required. |
| GET | `/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | `getAiLoadBalancer` | MCP_RESOURCE | opbx://ai-load-balancers/{id} (+ get_ai_load_balancer) | low | any | no |  |
| PATCH | `/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | `patchAiLoadBalancer` | MCP_TOOL | (merged into update_ai_load_balancer) | medium | cfg | no | Consolidated. |
| PUT | `/v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | `updateAiLoadBalancer` | MCP_TOOL | update_ai_load_balancer | medium | cfg | no |  |

## Auto Dialer (Campaigns + Distribution Lists)

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/auto-dialer-campaigns` | `listAutoDialerCampaigns` | MCP_TOOL | list_campaigns | low | cfg | no | viewAny restricted to owner|pbx_admin. |
| POST | `/v1/auto-dialer-campaigns` | `createAutoDialerCampaign` | MCP_TOOL | create_campaign | medium | cfg | no | routing_destination_id required unless type=hangup; caller-id pool validated at create. |
| GET | `/v1/auto-dialer-campaigns/available-caller-ids` | `getAvailableCallerIds` | MCP_TOOL | list_available_caller_ids | low | cfg | no | DIDs eligible for caller-id pools. |
| GET | `/v1/auto-dialer-campaigns/monitor/summary` | `getMonitorSummary` | MCP_TOOL | get_campaigns_monitor_summary | low | cfg | no | Org-wide campaign monitor. |
| DELETE | `/v1/auto-dialer-campaigns/{campaign}` | `deleteAutoDialerCampaign` | MCP_TOOL | delete_campaign | high | owner | yes | Owner only AND status DRAFT only. Confirmation required. |
| GET | `/v1/auto-dialer-campaigns/{campaign}` | `getAutoDialerCampaign` | MCP_RESOURCE | opbx://campaigns/{id} (+ get_campaign) | low | cfg | no |  |
| PATCH | `/v1/auto-dialer-campaigns/{campaign}` | `patchAutoDialerCampaign` | MCP_TOOL | (merged into update_campaign) | medium | cfg | no | Consolidated. |
| PUT | `/v1/auto-dialer-campaigns/{campaign}` | `updateAutoDialerCampaign` | MCP_TOOL | update_campaign | medium | cfg | no | caller_id_pool change blocked on ACTIVE (409). No status revalidation of destination at start (gap). |
| PATCH | `/v1/auto-dialer-campaigns/{campaign}/archive` | `archiveAutoDialerCampaign` | MCP_TOOL | archive_campaign | high | owner | yes | Owner only; pauses first if ACTIVE. Confirmation required. |
| GET | `/v1/auto-dialer-campaigns/{campaign}/caller-id-stats` | `getCampaignCallerIdStats` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | DUPLICATE spec entry for the same path+method as getCallerIdStats (index.yaml + caller-id-stats.yaml). |
| GET | `/v1/auto-dialer-campaigns/{campaign}/caller-id-stats` | `getCallerIdStats` | MCP_TOOL | get_campaign_caller_id_stats | low | cfg | no | Per-caller-ID performance stats. |
| GET | `/v1/auto-dialer-campaigns/{campaign}/concurrency` | `getCampaignConcurrency` | MCP_TOOL | (merged into get_campaign_status) | low | cfg | no | CAC/concurrency snapshot; folded into semantic status tool. |
| GET | `/v1/auto-dialer-campaigns/{campaign}/destinations` | `listCampaignDestinations` | MCP_TOOL | list_campaign_destinations | low | cfg | no | Paginated destination states. |
| DELETE | `/v1/auto-dialer-campaigns/{campaign}/list` | `deleteCampaignList` | INTERNAL_NOT_EXPOSED | - | medium | cfg | yes | Detaches list from campaign; covered semantically by unassign_distribution_list. Excluded v1. |
| GET | `/v1/auto-dialer-campaigns/{campaign}/list` | `getCampaignList` | MCP_TOOL | get_campaign_list | low | cfg | no | Which distribution list is assigned. |
| POST | `/v1/auto-dialer-campaigns/{campaign}/list` | `uploadCampaignList` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | CSV multipart upload against campaign. MCP uses JSON batch on distribution lists instead. |
| GET | `/v1/auto-dialer-campaigns/{campaign}/monitor/detail` | `getMonitorDetail` | MCP_TOOL | get_campaign_status | low | cfg | no | Primary semantic status tool (progress, counters, concurrency). |
| PATCH | `/v1/auto-dialer-campaigns/{campaign}/pause` | `pauseAutoDialerCampaign` | MCP_TOOL | pause_campaign | high | cfg | yes | ACTIVE only; marks in-flight sessions failed/cancelled, resets Redis CAC. Confirmation required. |
| POST | `/v1/auto-dialer-campaigns/{campaign}/reset-cac` | `resetCampaignCac` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Redis concurrency-counter reset; niche ops tool. Excluded v1. |
| POST | `/v1/auto-dialer-campaigns/{campaign}/reset-caller-id-cycle` | `resetCallerIdCycle` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Niche rotation reset. Excluded v1. |
| POST | `/v1/auto-dialer-campaigns/{campaign}/reset-caller-id-cycle` | `resetCampaignCallerIdCycle` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | DUPLICATE spec entry for the same path+method as resetCallerIdCycle (index.yaml + reset.yaml). |
| PATCH | `/v1/auto-dialer-campaigns/{campaign}/resume` | `resumeAutoDialerCampaign` | MCP_TOOL | resume_campaign | high | cfg | no | PAUSED only; resumes outbound calling. Confirmation required. |
| PATCH | `/v1/auto-dialer-campaigns/{campaign}/start` | `startAutoDialerCampaign` | MCP_TOOL | start_campaign | high | cfg | no | Originates real outbound calls. DRAFT or PAUSED only; requires list in 'ready' status. 409/422 message-only errors. Confirmation with preview (destinations, CPS, caller IDs) required. |

## Call Detail Records

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/call-detail-records` | `listCallDetailRecords` | MCP_TOOL | search_calls | low | any (supervisor scoped) | no | Rich filters; normalized pagination in MCP output. |
| GET | `/v1/call-detail-records/export` | `exportCdr` | INTERNAL_NOT_EXPOSED | - | low | any | no | DUPLICATE spec entry for same path+method. |
| GET | `/v1/call-detail-records/export` | `exportCallDetailRecords` | INTERNAL_NOT_EXPOSED | - | low | any | no | CSV file export; excluded v1. DUPLICATE spec entry (export.yaml + index.yaml). |
| GET | `/v1/call-detail-records/statistics` | `callDetailRecordStatistics` | MCP_TOOL | get_call_statistics | low | any (supervisor scoped) | no | Aggregates. DUPLICATE spec entry exists (statistics.yaml + index.yaml). |
| GET | `/v1/call-detail-records/statistics` | `getCdrStatistics` | INTERNAL_NOT_EXPOSED | - | low | any | no | DUPLICATE spec entry for same path+method. |
| GET | `/v1/call-detail-records/{call_detail_record}` | `getCallDetailRecord` | MCP_RESOURCE | opbx://call-detail-records/{id} (+ get_call_details) | low | any (supervisor scoped) | no |  |

## Recordings (Announcements)

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/storage/recordings/{path}` | `publicRecordingDownload` | EXECUTION_PLANE | - | medium | public (HMAC-signed) | no | Signed URL for Cloudonix to fetch IVR audio; unauthenticated by design. |
| GET | `/v1/recordings` | `listRecordings` | MCP_TOOL | list_recordings | low | cfg | no | Announcement/IVR audio files (NOT call recordings). Policy: owner|pbx_admin only. |
| POST | `/v1/recordings` | `createRecording` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Binary audio upload; impractical over MCP in v1. |
| GET | `/v1/recordings/download` | `downloadRecordingWithToken` | INTERNAL_NOT_EXPOSED | - | high | public (token) | no | Unauthenticated possession-based access endpoint; never exposed. |
| DELETE | `/v1/recordings/{recording}` | `deleteRecording` | INTERNAL_NOT_EXPOSED | - | high | cfg | yes | Deletes audio from MinIO. Excluded v1 (upload also excluded); future candidate with confirmation. |
| GET | `/v1/recordings/{recording}` | `getRecording` | MCP_RESOURCE | opbx://recordings/{id} (+ get_recording_metadata) | low | cfg | no | Metadata only; download URLs are bearer-equivalent tokens - not exposed. |
| PATCH | `/v1/recordings/{recording}` | `patchRecording` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Excluded v1. |
| PUT | `/v1/recordings/{recording}` | `updateRecording` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Rename/metadata edit; excluded v1. |
| GET | `/v1/recordings/{recording}/download` | `downloadRecording` | INTERNAL_NOT_EXPOSED | - | high | cfg | no | Issues bearer-equivalent download token (30 min, anonymous use allowed). PII/exfiltration risk; excluded. |

## Active Calls / Session Updates

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/session-updates/active` | `getActiveSessions` | MCP_TOOL | list_active_calls | low | any (supervisor scoped) | no | Hard cap 100; filters status/direction. |
| GET | `/v1/session-updates/active/stats` | `getActiveSessionStats` | MCP_TOOL | get_active_call_statistics | low | any | no |  |
| GET | `/v1/session-updates/{sessionId}` | `getSessionDetails` | MCP_TOOL | get_active_call | low | any (supervisor scoped) | no | Event history for a session. |
| POST | `/v1/session-updates/{sessionId}/coach-target` | `resolveCoachTarget` | MCP_TOOL | start_call_coaching | high | owner|supervisor (scoped) | no | policy spy|whisper|barge (+whisper_party). Returns dial destination for supervisor webphone; no stop endpoint (hangup ends). Confirmation required. |
| DELETE | `/v1/session-updates/{sessionId}/disconnect` | `disconnectSession` | MCP_TOOL | disconnect_call | high | owner | yes | Owner only. Terminates live call via Cloudonix DELETE session. Confirmation required. |

## Inbound Blacklist

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/inbound-blacklist` | `listInboundBlacklistEntrys` | MCP_TOOL | list_inbound_blacklist | low | any | no |  |
| POST | `/v1/inbound-blacklist` | `createInboundBlacklistEntry` | MCP_TOOL | block_inbound_number | medium | cfg | no | Adds security rule (exact|prefix|pattern match). |
| GET | `/v1/inbound-blacklist/blocked-logs` | `getBlockedCallLogs` | MCP_TOOL | list_blocked_calls | low | any | no | Blocked-call log; supports diagnose_call_problem prompt. |
| GET | `/v1/inbound-blacklist/statistics` | `getBlacklistStatistics` | MCP_TOOL | get_inbound_blacklist_statistics | low | any | no |  |
| PATCH | `/v1/inbound-blacklist/{inboundBlacklist}/toggle-status` | `toggleBlacklistStatus` | MCP_TOOL | set_inbound_blacklist_status | medium | cfg | no | Enable/disable without delete. |
| DELETE | `/v1/inbound-blacklist/{inbound_blacklist}` | `deleteInboundBlacklistEntry` | MCP_TOOL | unblock_inbound_number | high | cfg | yes | Security-rule removal; confirmation required. |
| GET | `/v1/inbound-blacklist/{inbound_blacklist}` | `getInboundBlacklistEntry` | MCP_RESOURCE | opbx://inbound-blacklist/{id} (+ get_inbound_blacklist_entry) | low | any | no |  |
| PATCH | `/v1/inbound-blacklist/{inbound_blacklist}` | `patchInboundBlacklistEntry` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Excluded v1. |
| PUT | `/v1/inbound-blacklist/{inbound_blacklist}` | `updateInboundBlacklistEntry` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Entry edit; create/delete/toggle cover intent in v1. |

## Outbound Whitelist

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/outbound-whitelist` | `listOutboundWhitelistEntrys` | MCP_TOOL | list_outbound_whitelist | low | any | no |  |
| POST | `/v1/outbound-whitelist` | `createOutboundWhitelistEntry` | MCP_TOOL | add_outbound_whitelist_rule | medium | cfg | no |  |
| PATCH | `/v1/outbound-whitelist/{outboundWhitelist}/toggle-status` | `toggleWhitelistStatus` | MCP_TOOL | set_outbound_whitelist_status | medium | cfg | no |  |
| DELETE | `/v1/outbound-whitelist/{outbound_whitelist}` | `deleteOutboundWhitelistEntry` | MCP_TOOL | remove_outbound_whitelist_rule | high | cfg | yes | Security-rule removal; confirmation required. |
| GET | `/v1/outbound-whitelist/{outbound_whitelist}` | `getOutboundWhitelistEntry` | MCP_RESOURCE | opbx://outbound-whitelist/{id} (+ get_outbound_whitelist_entry) | low | any | no |  |
| PATCH | `/v1/outbound-whitelist/{outbound_whitelist}` | `patchOutboundWhitelistEntry` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Excluded v1. |
| PUT | `/v1/outbound-whitelist/{outbound_whitelist}` | `updateOutboundWhitelistEntry` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Excluded v1 (see blacklist rationale). |

## Users

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/users` | `listUsers` | MCP_TOOL | list_users | low | cfg|supervisor (scoped) | no |  |
| POST | `/v1/users` | `createUser` | MCP_TOOL | create_user | medium | cfg | no | Direct create; invite flow preferred for real users. |
| POST | `/v1/users/invite` | `inviteUser` | MCP_TOOL | invite_user | medium | cfg | no | Email invitation flow. |
| POST | `/v1/users/invite/accept` | `acceptInvitation` | AUTH_INFRASTRUCTURE | - | low | public | no | Invitation plumbing. |
| GET | `/v1/users/invite/validate` | `validateInvitationToken` | AUTH_INFRASTRUCTURE | - | low | public | no | Invitation plumbing. |
| DELETE | `/v1/users/{user}` | `deleteUser` | MCP_TOOL | delete_user | high | cfg | yes | Confirmation required. |
| GET | `/v1/users/{user}` | `getUser` | MCP_RESOURCE | opbx://users/{id} (+ get_user) | low | cfg|supervisor (scoped) | no | No credentials in payload (safe). |
| PATCH | `/v1/users/{user}` | `patchUser` | MCP_TOOL | (merged into update_user) | medium | cfg | no | Consolidated. |
| PUT | `/v1/users/{user}` | `updateUser` | MCP_TOOL | update_user | medium | cfg (role changes: owner) | no | updateRole is owner-only (UserPolicy). |
| GET | `/v1/users/{user}/embed-token` | `getUserEmbedToken` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Embed credential; excluded. |
| PATCH | `/v1/users/{user}/embed-token` | `updateUserEmbedToken` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Embed credential; excluded. |
| POST | `/v1/users/{user}/embed-token/regenerate` | `regenerateUserEmbedToken` | INTERNAL_NOT_EXPOSED | - | medium | cfg | yes | Embed credential; excluded. |
| PATCH | `/v1/users/{user}/password` | `updateUserPassword` | INTERNAL_NOT_EXPOSED | - | high | cfg (restricted by target role) | yes | Credential reset; excluded from agent surface v1. |

## Supervisors

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/dashboard/supervisor` | `getSupervisorDashboard` | MCP_TOOL | get_supervisor_dashboard | low | cfg|supervisor | no | Aggregate live-stats view. |
| GET | `/v1/supervisors/{user}/assignments` | `getSupervisorAssignments` | MCP_TOOL | get_supervisor_assignments | low | cfg | no | Which users/resources a supervisor can see. |
| PUT | `/v1/supervisors/{user}/assignments` | `updateSupervisorAssignments` | MCP_TOOL | update_supervisor_assignments | medium | cfg | no |  |

## Call Tracking

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/call-tracking-analytics` | `getCallTrackingAnalytics` | MCP_TOOL | get_call_tracking_analytics | low | any | no | Attribution analytics; fits call-analytics intent. |
| GET | `/v1/call-tracking-analytics/export` | `exportCallTrackingAnalytics` | INTERNAL_NOT_EXPOSED | - | low | any | no | File export; excluded v1. |
| GET | `/v1/call-tracking-campaigns` | `listCallTrackingCampaigns` | MCP_TOOL | list_call_tracking_campaigns | low | any | no |  |
| POST | `/v1/call-tracking-campaigns` | `createCallTrackingCampaign` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Write surface deferred (v2); read-only in v1. |
| DELETE | `/v1/call-tracking-campaigns/{call_tracking_campaign}` | `deleteCallTrackingCampaign` | INTERNAL_NOT_EXPOSED | - | high | cfg | yes | Deferred v2. |
| GET | `/v1/call-tracking-campaigns/{call_tracking_campaign}` | `getCallTrackingCampaign` | MCP_RESOURCE | opbx://call-tracking-campaigns/{id} (+ get_call_tracking_campaign) | low | any | no |  |
| PATCH | `/v1/call-tracking-campaigns/{call_tracking_campaign}` | `patchCallTrackingCampaign` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| PUT | `/v1/call-tracking-campaigns/{call_tracking_campaign}` | `updateCallTrackingCampaign` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| GET | `/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers` | `listCallTrackingNumbers` | MCP_TOOL | list_call_tracking_numbers | low | any | no | DNI pool numbers per campaign. |
| POST | `/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers` | `createCallTrackingNumber` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| DELETE | `/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers/{call_tracking_number}` | `deleteCallTrackingNumber` | INTERNAL_NOT_EXPOSED | - | high | cfg | yes | Deferred v2. |
| PATCH | `/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers/{call_tracking_number}` | `patchCallTrackingNumber` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| PUT | `/v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers/{call_tracking_number}` | `updateCallTrackingNumber` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| GET | `/v1/call-tracking-campaigns/{call_tracking_campaign}/notification-logs` | `listCallTrackingNotificationLogs` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Deferred v2. |
| GET | `/v1/call-tracking-campaigns/{call_tracking_campaign}/notification-settings` | `getCallTrackingNotificationSettings` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Deferred v2. |
| PUT | `/v1/call-tracking-campaigns/{call_tracking_campaign}/notification-settings` | `updateCallTrackingNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Deferred v2. |
| POST | `/v1/call-tracking-campaigns/{call_tracking_campaign}/notification-settings/test` | `testCallTrackingNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Sends real webhooks; deferred v2. |
| GET | `/v1/call-tracking-sessions` | `listCallTrackingSessions` | MCP_TOOL | list_call_tracking_sessions | low | any | no | Per-visitor tracking sessions. |

## Call Tracking Ad Platforms

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/call-tracking-ad-platform-integrations` | `getAdPlatformIntegration` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Third-party ad-platform credentials; deferred v2. |
| PUT | `/v1/call-tracking-ad-platform-integrations` | `updateAdPlatformIntegration` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Third-party credentials; deferred v2. |

## Call Tracking DNI

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/call-tracking-dni/swap` | `callTrackingDniSwap` | EXECUTION_PLANE | - | low | public | no | Public runtime number-swap for websites; execution-plane, not tenant management. |

## Call Notifications

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/call-notifications/logs` | `getNotificationLogs` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| GET | `/v1/call-notifications/logs/{sessionToken}` | `getSessionNotificationLogs` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| GET | `/v1/call-notifications/rate-limit` | `getNotificationRateLimit` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| DELETE | `/v1/call-notifications/settings` | `deleteNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| GET | `/v1/call-notifications/settings` | `getNotificationSettings` | INTERNAL_NOT_EXPOSED | - | low | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| POST | `/v1/call-notifications/settings` | `createNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| PUT | `/v1/call-notifications/settings` | `updateNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |
| POST | `/v1/call-notifications/settings/test` | `testNotificationSettings` | INTERNAL_NOT_EXPOSED | - | medium | cfg | no | Outbound webhook-notification config; excluded v1. Logs are a future candidate for diagnose_call_problem. |

## Organizations (Join Requests)

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/organizations/join-requests` | `listOrganizationJoinRequests` | INTERNAL_NOT_EXPOSED | - | low | owner | no | Org membership workflow; excluded v1. |
| POST | `/v1/organizations/join-requests` | `createOrganizationJoinRequest` | AUTH_INFRASTRUCTURE | - | low | public | no | Public signup plumbing. |
| POST | `/v1/organizations/join-requests/{joinRequest}/approve` | `approveOrganizationJoinRequest` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Excluded v1. |
| POST | `/v1/organizations/join-requests/{joinRequest}/reject` | `rejectOrganizationJoinRequest` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Excluded v1. |

## Profile

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/profile` | `getProfile` | INTERNAL_NOT_EXPOSED | - | low | any | no | Identity is resolved via GET /auth/me inside the MCP auth layer; not an agent tool. |
| PUT | `/v1/profile` | `updateProfile` | INTERNAL_NOT_EXPOSED | - | low | any | no | Personal profile edit; not PBX operations. |
| PUT | `/v1/profile/organization` | `updateOrganization` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Org settings edit; excluded v1 (sensitive, low agent value). |
| PUT | `/v1/profile/password` | `updatePassword` | INTERNAL_NOT_EXPOSED | - | high | any | yes | Credential management; never an agent tool. |

## Settings (Cloudonix)

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/settings/cloudonix` | `getCloudonixSettings` | INTERNAL_NOT_EXPOSED | - | high | owner | no | CPaaS credentials/secrets surface; secrets must not transit MCP. |
| PUT | `/v1/settings/cloudonix` | `updateCloudonixSettings` | INTERNAL_NOT_EXPOSED | - | high | owner | yes | Changes CPaaS credentials; excluded. |
| POST | `/v1/settings/cloudonix/generate-requests-key` | `generateRequestsKey` | INTERNAL_NOT_EXPOSED | - | high | owner | yes | Rotates webhook auth key (breaks Cloudonix webhooks until reconfigured); excluded. |
| GET | `/v1/settings/cloudonix/outbound-trunks` | `getOutboundTrunks` | INTERNAL_NOT_EXPOSED | - | low | owner | no | Cloudonix trunk inventory; future read candidate. |
| POST | `/v1/settings/cloudonix/validate` | `validateCloudonixCredentials` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Tied to settings surface; excluded v1. |

## API Keys

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/api-keys` | `listApiKeys` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Machine-credential management; MCP never manages API keys. |
| POST | `/v1/api-keys` | `createApiKey` | INTERNAL_NOT_EXPOSED | - | high | owner | no | Excluded. |
| GET | `/v1/api-keys/grantable-resources` | `listGrantableResources` | INTERNAL_NOT_EXPOSED | - | low | owner | no | Excluded. |
| DELETE | `/v1/api-keys/{apiKey}` | `revokeApiKey` | INTERNAL_NOT_EXPOSED | - | high | owner | yes | Excluded. |
| GET | `/v1/api-keys/{apiKey}` | `getApiKey` | INTERNAL_NOT_EXPOSED | - | medium | owner | no | Excluded. |
| PATCH | `/v1/api-keys/{apiKey}` | `patchApiKey` | INTERNAL_NOT_EXPOSED | - | high | owner | no | Excluded. |
| PUT | `/v1/api-keys/{apiKey}` | `updateApiKey` | INTERNAL_NOT_EXPOSED | - | high | owner | no | Excluded. |

## Config

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/config/application` | `getApplicationConfig` | INTERNAL_NOT_EXPOSED | - | low | public | no | Public app config; no agent value. |

## Authentication

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/sanctum/csrf-cookie` | `getCsrfCookie` | AUTH_INFRASTRUCTURE | - | low | public | no | Sanctum SPA plumbing. |
| GET | `/v1/auth/auth0/callback` | `auth0Callback` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/auth0/link` | `auth0Link` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/auth0/redirect` | `auth0Redirect` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/auth0/unlink` | `auth0Unlink` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/login` | `login` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/logout` | `logout` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| GET | `/v1/auth/me` | `getCurrentUser` | AUTH_INFRASTRUCTURE | (internal: MCP identity resolution) | low | any | no | GET /auth/me - used by the MCP auth layer to validate PATs and derive user/org/role/is_platform_manager. Not exposed as a tool. |
| POST | `/v1/auth/refresh` | `refreshAuth` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| POST | `/v1/auth/register` | `register` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| GET | `/v1/auth/register/validate` | `validateRegistration` | AUTH_INFRASTRUCTURE | - | medium | public | no | Interactive/session auth plumbing; MCP clients authenticate with their own OPBX credential, never via these endpoints. |
| GET | `/v1/validate-email` | `validateEmail` | INTERNAL_NOT_EXPOSED | - | low | public | no | Registration helper. |

## Broadcasting

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/broadcasting/auth` | `authenticateBroadcastingChannelGet` | AUTH_INFRASTRUCTURE | - | low | any | no | Same, GET variant. |
| POST | `/v1/broadcasting/auth` | `authenticateBroadcastingChannel` | AUTH_INFRASTRUCTURE | - | low | any | no | Laravel Echo/Pusher channel authorization. |

## Voice Routing (CXML)

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| POST | `/callbacks/voice/albs-follow-through` | `albsFollowThrough` | EXECUTION_PLANE | - | high | voice webhook Bearer | yes | Mid-call CXML callback. |
| POST | `/callbacks/voice/ring-group-callback` | `ringGroupCallback` | EXECUTION_PLANE | - | high | voice webhook Bearer | yes | Mid-call CXML callback. |
| POST | `/voice/amd-action` | `amdAction` | EXECUTION_PLANE | - | high | AMD worker token | yes | Executes hangup/app-switch on live calls. |
| GET | `/voice/health` | `voiceHealth` | INTERNAL_NOT_EXPOSED | - | low | public | no | Infra health; MCP has own /health. |
| POST | `/voice/ivr-input` | `handleIvrInput` | EXECUTION_PLANE | - | high | voice webhook Bearer | yes | Mid-call CXML callback. |
| POST | `/voice/route` | `voiceRoute` | EXECUTION_PLANE | - | high | voice webhook Bearer | yes | Real-time CXML routing; Cloudonix-facing. |

## Webhooks

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| POST | `/webhooks/auto-dialer/amd-result` | `autoDialerAmdResult` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/auto-dialer/call-status` | `autoDialerCallStatus` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/cloudonix/call-initiated` | `callInitiatedWebhook` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/cloudonix/call-status` | `callStatusWebhook` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/cloudonix/cdr` | `cdrWebhook` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/cloudonix/dialer` | `dialerWebhookProxy` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |
| POST | `/webhooks/cloudonix/session-update` | `sessionUpdateWebhook` | EXECUTION_PLANE | - | high | webhook HMAC/Bearer | yes | Inbound CPaaS event ingestion (writes CDRs/sessions/campaign state); replay/forgery risk if exposed. |

## Webphone

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/webphone/calls-log` | `getWebPhoneCallsLog` | INTERNAL_NOT_EXPOSED | - | low | any | no | Webphone UI feed; covered by search_calls. |
| GET | `/v1/webphone/config` | `getWebPhoneConfig` | INTERNAL_NOT_EXPOSED | - | high | any | no | Returns per-user SIP/WebRTC credentials; credential-leak risk. |

## Embed Dialer

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/embed/calls-log` | `getEmbedCallsLog` | INTERNAL_NOT_EXPOSED | - | medium | embed token | no | Public embed-dialer surface. |
| GET | `/v1/embed/config` | `getEmbedConfig` | INTERNAL_NOT_EXPOSED | - | medium | embed token | no | Public embed-dialer surface. |

## Platform Management

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/v1/platform/audit-logs` | `getAuditLogs` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/dashboard` | `getPlatformDashboard` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| DELETE | `/v1/platform/operate-as` | `stopOperateAsOrganization` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| POST | `/v1/platform/operate-as/{organization}` | `startOperateAsOrganization` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/organizations` | `listOrganizations` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/organizations/{organization}` | `getOrganization` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| PUT | `/v1/platform/organizations/{organization}` | `updatePlatformOrganization` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| PATCH | `/v1/platform/organizations/{organization}/status` | `updateOrganizationStatus` | PLATFORM_ADMIN_ONLY | - | high | pm | yes | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/organizations/{organization}/users` | `listOrganizationUsers` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| POST | `/v1/platform/organizations/{organization}/users` | `createOrganizationUser` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/users` | `listAllUsers` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| DELETE | `/v1/platform/users/{user}` | `deletePlatformUser` | PLATFORM_ADMIN_ONLY | - | high | pm | yes | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| GET | `/v1/platform/users/{user}` | `getPlatformUser` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| PUT | `/v1/platform/users/{user}` | `updatePlatformUser` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| PATCH | `/v1/platform/users/{user}/password` | `updatePlatformUserPassword` | PLATFORM_ADMIN_ONLY | - | high | pm | yes | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |
| PATCH | `/v1/platform/users/{user}/platform-manager` | `setPlatformManager` | PLATFORM_ADMIN_ONLY | - | high | pm | no | Cross-tenant platform-manager API; isolated from tenant catalog. Future separate privileged MCP server. |

## Misc

| Method | Path | operationId | Class | Proposed MCP name | Risk | Role | Destr. | Reason |
|---|---|---|---|---|---|---|---|---|
| GET | `/health` | `publicHealthCheck` | INTERNAL_NOT_EXPOSED | - | low | public | no | Infra health (exposes DB/Redis status); MCP has own /health. |
| GET | `/storage/health` | `storageHealthCheck` | INTERNAL_NOT_EXPOSED | - | low | public | no | Infra health. |
| GET | `/websocket/health` | `websocketHealthCheck` | INTERNAL_NOT_EXPOSED | - | low | public | no | Infra health. |
