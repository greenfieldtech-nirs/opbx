# Trunk Management

> **Last Updated**: 2026-09-09
> **Status**: ACTIVE — shipped on `feature/trunk-management`
> **Depends On**: Settings & Cloudonix (credentials), Scoped API Keys, MCP Server
> **Spec**: `.my_agent/specifications/2026-09-09-trunk-management-design.md`
> **Workplan**: `.my_agent/workplans/2026-09-09-trunk-management.md`

---

## Overview

Owner/PBX-Admin management of Cloudonix voice trunks (inbound = carrier→PBX, outbound = PBX→world).
**Live proxy** to the Cloudonix API — no MySQL table, no sync. Tenant isolation via the org's
Cloudonix credentials (`CloudonixSettings`, NOT org-scoped — always filter by organization_id).

## Cloudonix API Facts (live-verified 2026-09-09 on dev domain)

- Endpoints: `GET|POST /customers/self/domains/{domainUuid}/trunks`, `GET|PUT|DELETE .../trunks/{id}`
- Path `{id}` accepts BOTH int `id` and `uuid`.
- **Direction normalization on create**: POST `direction: "inbound"` → trunk becomes `public-inbound`;
  `"outbound"` → `public-outbound`. Expected, not a bug.
- **No read-only trunks**: all trunks equal (incl. ones from the Cloudonix web app like
  `outbound-telnyx`); PUT/DELETE work on public-*. A read-only guard was added then removed (a8624091).
- GET returns `profile.authentication.password` in cleartext → always mask via TrunkResource.
- PUT cannot rename; OPBX also treats direction as immutable post-create.
- OpenAPI spec: `https://developers.cloudonix.com/openapi/cloudonix/v1/default.yaml` (+ Trunks.yaml).

## Source Files

| File | Purpose |
|------|---------|
| `app/Services/CloudonixClient/CloudonixTrunksClient.php` | Full CRUD + list; `forgetTrunkCaches()` on mutations; `lastHttpStatus` (getTrunk) distinguishes 404 from 5xx |
| `app/Http/Controllers/Api/TrunkController.php` | Plain controller (no model); index `?direction=` filter; `in_use_by` from `OutboundWhitelist.outbound_trunk_name` (single whereIn); 502 `cloudonix_unavailable` on null list; 404 vs 502 via `trunkFetchError()` |
| `app/Http/Resources/TrunkResource.php` | Static `present()`; masks password; `has_credentials`, `in_use_by` |
| `app/Http/Requests/StoreTrunkRequest.php` / `UpdateTrunkRequest.php` | direction create-only inbound\|outbound; username↔password `required_with` paired; update all `sometimes`, no name/direction |
| `routes/api.php` | `Route::apiResource('trunks', ...)->only([...])` in the protected group; names `trunks.*`; `{trunk}` plain string |
| `app/Enums/GrantableResource.php` | `TRUNKS = 'trunks'` (slug = route prefix) |
| `frontend/src/pages/Trunks.tsx` | Table, direction tabs (public-* grouped), refresh, create/edit dialog (auth collapsible, password "leave blank to keep"), delete AlertDialog with `in_use_by` acknowledgment |
| `frontend/src/services/trunks.service.ts` | API client + Trunk type (no password/read_only) |
| `frontend/src/router.tsx` | `/ui/trunks` via `OwnerRoute roles={['owner','pbx_admin']}` (OwnerRoute gained optional `roles` prop, default ['owner']) |
| `frontend/src/components/Layout/Sidebar.tsx` | Trunks entry after Phone Numbers |
| `docs/opbx-openapi/paths/trunks/*.yaml`, `components/schemas/Trunk.yaml` | Spec; operationIds listTrunks/createTrunk/getTrunk/updateTrunk/deleteTrunk |
| `mcp-server/src/tools/trunks.ts` | 5 MCP tools (see mcp-server.md) |

## Error Contract

- null from client on list → 502 `cloudonix_unavailable`; warm-cache outage serves stale (intentional, pinned in test comment)
- getTrunk null: lastHttpStatus 404 → 404; 5xx/exception (null status) → 502
- Key-authenticated calls: `EnforceApiKeyScope` (trunks grant required); `authorizeTrunks` owner-shim passes keys

## Tests

- `tests/Unit/Services/CloudonixClient/CloudonixTrunksClientTest.php` (7)
- `tests/Feature/Api/TrunkControllerTest.php` (17)
- `tests/Feature/ApiKey/ApiKeyCoverageTest.php::test_trunks_are_grantable`
- NOTE: `.env.testing` sets `CLOUDONIX_API_BASE_URL=http://localhost` — build Http::fake URLs from `config('cloudonix.api.base_url')`, never hardcode api.cloudonix.io.

## Known Limitations / Follow-ups

- **Cannot clear prefix/credentials on edit**: UI omits empty fields from PUT; Cloudonix null-semantics unverified. Add "remove credentials" affordance after verifying upstream behavior.
- MCP trunk tools address by numeric id only (`z.coerce.number()`); uuid addressing possible but unwired.
- `list_trunks` MCP tool exposes page/per_page though the endpoint is unpaginated (cosmetic).
