# Trunk Management — Design Spec

> **Date**: 2026-09-09
> **Status**: APPROVED (user-approved 2026-09-09)
> **Module**: New — Trunk Management (Settings & Cloudonix dependent)

---

## 1. Goal

Add an OPBX page to manage Cloudonix voice trunks (inbound and outbound) per organization.
Inbound trunks carry calls from carriers/equipment into the PBX; outbound trunks carry calls
from extensions/users to the world.

## 2. Cloudonix API Contract (verified against official OpenAPI spec)

Source: `https://developers.cloudonix.com/openapi/cloudonix/v1/default.yaml` (+ `Trunks.yaml`, `Components.yaml`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/customers/{cid}/domains/{did}/trunks` | List (plain JSON array) |
| POST | `/customers/{cid}/domains/{did}/trunks` | Create |
| GET | `/customers/{cid}/domains/{did}/trunks/{id}` | Get one |
| PUT | `/customers/{cid}/domains/{did}/trunks/{id}` | Update |
| DELETE | `/customers/{cid}/domains/{did}/trunks/{id}` | Delete → 204 |

`voicetrunkObject`: `id` (int), `uuid`, `name`, `ip` (IP or hostname), `port` (string, usually 5060),
`transport` (`udp`|`tcp`|`tls`), `prefix` (optional, prepended to incoming calls),
`direction` (`inbound`|`outbound`|`public-inbound`|`public-outbound`),
`profile.authentication` (`username`, `password`, `overwrite-from`), `active`, timestamps.

**Constraints**:
- `PUT` cannot rename — `name` is create-only.
- GET returns `profile.authentication.password` in cleartext → OPBX must mask it in responses;
  only send password on update when user typed a new one.
- Path `{id}`: **verified 2026-09-09** — both int id and uuid work.
- ~~`public-*` directions are Cloudonix platform trunks~~ **CORRECTED 2026-09-09** (live spike): all trunks
  are equal; `outbound-telnyx` etc. were created via the Cloudonix web app. Cloudonix **normalizes
  direction on create**: `inbound`→`public-inbound`, `outbound`→`public-outbound`. PUT/DELETE work on
  public-* trunks. No read-only concept exists; the guard and `read_only` field were removed (a8624091).

## 3. Decisions (user-confirmed)

1. **Live proxy** to Cloudonix — no MySQL table, no sync. Tenant isolation via org's domain credentials.
2. ~~**Public trunks**: listed, read-only badge, edit/delete blocked~~ **CORRECTED**: no read-only concept;
   all trunks fully manageable. Delete safety = confirm dialog + in-use warning only.
   UI should display normalized directions clearly (inbound/public-inbound → "Inbound").
3. **Placement**: own sidebar page "Trunks", Owner + PBX Admin.
4. **Delete safety**: warn (not block) when trunk name is referenced by `outbound_whitelist.outbound_trunk_name`.

## 4. Backend Design (no new tables)

- `CloudonixTrunksClient`: add `createTrunk()`, `getTrunk()`, `updateTrunk()`, `deleteTrunk()`;
  invalidate list cache (`cloudonix:trunks:{domain}`, `cloudonix:outbound_trunks:{domain}`) on mutation.
- `App\Http\Controllers\Api\TrunkController` — plain controller (no local model → not AbstractApiCrudController):
  `index`, `store`, `show`, `update`, `destroy`. Routes `/api/v1/trunks` with `tenant.scope` + Owner/Admin role.
- FormRequests `StoreTrunkRequest` / `UpdateTrunkRequest`:
  name (create-only, 3–64), ip (IP or hostname), port (1–65535), transport/direction enums,
  prefix optional, profile.authentication optional (password write-only).
- `TrunkResource` (transformer): masks password (`has_credentials: bool`), adds `read_only` (public-*),
  adds `in_use_by` (whitelist entries referencing the name) on show/index for delete warning.
- `GrantableResource::TRUNKS` → `trunks.read|create|update|delete` for scoped API keys.

## 5. Frontend Design

- `frontend/src/pages/Trunks.tsx`: table (Name, Direction badge, Address ip:port + transport chip,
  Prefix, Auth yes/no, Status, Created); direction filter tabs; refresh button;
  mandatory empty-state pattern (icon/heading/contextual message/CTA).
- Create/Edit dialog: direction picker with contextual help; name disabled on edit; IP/host, port,
  transport, prefix; collapsible Authentication section; public trunks → read-only detail view.
- Delete: confirm dialog showing `in_use_by` warning when referenced; blocked for public-*.
- `frontend/src/services/trunks.service.ts`, TanStack Query; sidebar nav item (Network icon),
  gated Owner+Admin.

## 6. MCP Integration

Dependency chain: OPBX OpenAPI spec → typed client regen → tool definitions → docs/contract tests.

1. `docs/opbx-openapi/paths/trunks/*.yaml` + register in `openapi.yaml`.
2. `npm run generate:opbx-client` (typed client regen).
3. `mcp-server/src/tools/trunks.ts`: `list_trunks`, `get_trunk`, `create_trunk`, `update_trunk`,
   `delete_trunk` via defineListTool/defineGetTool/defineWriteTool, `permission: "trunks.*"`.
4. Register in `tools/index.ts`; `npm run generate:docs`; `npm run validate:opbx-api` green.
5. Password masking inherited from API layer (LLM agents never receive trunk credentials).

## 7. Phases & Checkpoints

| Phase | Scope | Checkpoint |
|---|---|---|
| 1 | Client CRUD, controller, routes, FormRequests, masking | Feature tests (Http::fake): CRUD, password never serialized, public-* mutation → 403, validation |
| 2 | `in_use_by` from outbound_whitelist | Test: referenced trunk flagged |
| 3 | Frontend page + dialogs + sidebar | type-check + lint; manual UI pass incl. empty state |
| 4 | GrantableResource, OPBX OpenAPI, MCP tools | ApiKeyCoverageTest green; MCP contract tests green; smoke via scoped key |
| 5 | Regression | run-tests.sh, pint, project-regression-tester |

## 8. Risks

- trunk-id path param type (int id vs uuid) — Phase 1 spike against live API.
- If Cloudonix omits password on GET, masking becomes a harmless no-op.
- Circuit breaker fallback returns `[]` on list — UI must distinguish "no trunks" from "Cloudonix unreachable" (surface error state when client returns null).
