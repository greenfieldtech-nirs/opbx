# Trunk Management — Implementation Plan

> **Spec**: `.my_agent/specifications/2026-09-09-trunk-management-design.md` (approved 2026-09-09)
> **Branch**: `feature/reg-free-dialing-receiver` (current working branch)
> **Test rules**: MySQL only via `./run-tests.sh`; `/tests` is gitignored → commit test files with `git add -f`; PHP lint `vendor/bin/pint --dirty`; FE `npm run type-check && npm run lint`.

**Goal:** Owner/PBX-Admin can list, create, edit, and delete Cloudonix voice trunks (inbound + outbound) from a new OPBX "Trunks" page; live-proxied to Cloudonix, no local table; public-* trunks read-only; MCP tools included.

**Architecture:** OPBX API is a live proxy to `https://api.cloudonix.io/customers/self/domains/{domain_uuid}/trunks` (Bearer auth via existing `CloudonixBaseClient`). Frontend React page via TanStack Query. MCP tools wrap the new OPBX endpoints.

---

## Phase 1 — Backend: Client CRUD + API

### Task 1: Extend `CloudonixTrunksClient` with CRUD

**Files:**
- Modify: `app/Services/CloudonixClient/CloudonixTrunksClient.php`
- Test: `tests/Unit/Services/CloudonixClient/CloudonixTrunksClientTest.php` (new)

- [ ] **Step 1: Write failing tests** (`Http::fake()` on `https://api.cloudonix.io/*`; construct client from a `CloudonixSettings` fixture with `domain_uuid`/`domain_api_key`):
  - `test_create_trunk_posts_to_trunks_endpoint` — asserts POST body contains name/ip/port/transport/direction, returns parsed array.
  - `test_get_trunk_returns_object`
  - `test_update_trunk_puts_fields`
  - `test_delete_trunk_returns_true_on_204`
  - `test_mutations_invalidate_trunk_caches` — prime `listTrunks()` cache, call `updateTrunk()`, assert cache keys `cloudonix:trunks:{domain}` and `cloudonix:outbound_trunks:{domain}` are gone.

- [ ] **Step 2: Implement** — add to `CloudonixTrunksClient`:

```php
public function createTrunk(array $data): ?array
{
    $this->requireDomainUuid();
    return $this->withCircuitBreaker(
        callback: function () use ($data) {
            $response = $this->client()->post("/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks", $data);
            if ($response->successful()) {
                $this->forgetTrunkCaches();
                return $response->json();
            }
            Log::warning('Cloudonix create trunk failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        },
        cacheKey: null,
        fallbackValue: null
    );
}

public function getTrunk(int|string $trunkId): ?array   // GET .../trunks/{id}
public function updateTrunk(int|string $trunkId, array $data): ?array  // PUT; on success forgetTrunkCaches()
public function deleteTrunk(int|string $trunkId): bool  // DELETE; true on 204; forgetTrunkCaches()

private function forgetTrunkCaches(): void
{
    Cache::forget("cloudonix:trunks:{$this->getDomainUuid()}");
    Cache::forget("cloudonix:outbound_trunks:{$this->getDomainUuid()}");
}
```

All mutations log with `domain_uuid` context; failures return null/false (never throw past the circuit breaker).

- [ ] **Step 3:** `./run-tests.sh --filter=CloudonixTrunksClientTest` green.
- [ ] **Step 4: Spike — id vs uuid**: against the live dev domain, `GET .../trunks/{id}` with both the numeric `id` and `uuid` of an existing trunk; record which works in the commit message. Use the working one as the OPBX path param going forward.
- [ ] **Step 5: Commit** `feat(trunks): CloudonixTrunksClient full CRUD with cache invalidation` (`git add -f` the test).

### Task 2: TrunkResource (masking presenter)

**Files:**
- Create: `app/Http/Resources/TrunkResource.php`

- [ ] Static presenter (no Eloquent model exists):

```php
class TrunkResource
{
    /** @param array<int, string> $inUseBy names of whitelist entries referencing this trunk */
    public static function present(array $trunk, array $inUseBy = []): array
    {
        $auth = $trunk['profile']['authentication'] ?? [];
        $direction = $trunk['direction'] ?? '';
        return [
            'id' => $trunk['id'] ?? null,
            'uuid' => $trunk['uuid'] ?? null,
            'name' => $trunk['name'] ?? null,
            'direction' => $direction,
            'ip' => $trunk['ip'] ?? null,
            'port' => $trunk['port'] ?? null,
            'transport' => $trunk['transport'] ?? null,
            'prefix' => $trunk['prefix'] ?? null,
            'active' => $trunk['active'] ?? true,
            'created_at' => $trunk['createdAt'] ?? null,
            'has_credentials' => !empty($auth['username']),
            'username' => $auth['username'] ?? null,
            // password NEVER serialized
            'overwrite_from' => (bool) ($auth['overwrite-from'] ?? false),
            'read_only' => in_array($direction, ['public-inbound', 'public-outbound'], true),
            'in_use_by' => $inUseBy,
        ];
    }
}
```

### Task 3: FormRequests

**Files:**
- Create: `app/Http/Requests/StoreTrunkRequest.php`, `app/Http/Requests/UpdateTrunkRequest.php`

- [ ] Store: `name` required|string|min:3|max:64; `ip` required|string|max:255|regex IP-or-hostname; `port` required|integer|min:1|max:65535; `transport` required|in:udp,tcp,tls; `direction` required|in:inbound,outbound (public-* not creatable); `prefix` nullable|string|max:20; `username`/`password`/`overwrite_from` optional (password required if username present).
- [ ] Update: same minus `name` and `direction` (both immutable post-create per API contract: name unsupported by PUT; direction kept immutable to avoid confusing in-use trunks).
- [ ] `authorize()`: `$this->user()->isOwner() || $this->user()->isPBXAdmin()`.

### Task 4: TrunkController + routes

**Files:**
- Create: `app/Http/Controllers/Api/TrunkController.php`
- Modify: `routes/api.php` (inside the protected `resolve.api.key, auth:sanctum, tenant.scope, operate.as, rate_limit_org:api, enforce.api.key.scope` group)
- Test: `tests/Feature/Api/TrunkControllerTest.php` (new)

- [ ] Routes (name prefix `trunks.` — matches `GrantableResource::TRUNKS` added in Phase 4):

```php
Route::apiResource('trunks', TrunkController::class)
    ->only(['index', 'show', 'store', 'update', 'destroy'])
    ->parameters(['trunks' => 'trunk']); // {trunk} is a Cloudonix id/uuid string, no model binding
```

- [ ] Controller shape:

```php
class TrunkController extends Controller
{
    private function client(): CloudonixClient
    {
        $settings = CloudonixSettings::query()->firstOrFail(); // OrganizationScope applies
        return new CloudonixClient($settings);
    }

    private function authorizeTrunks(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isOwner() || $user->isPBXAdmin()), 403);
    }

    public function index(Request $request): JsonResponse  // ?direction=inbound|outbound|public-inbound|public-outbound filter
    public function show(string $trunk): JsonResponse
    public function store(StoreTrunkRequest $request): JsonResponse   // 201
    public function update(UpdateTrunkRequest $request, string $trunk): JsonResponse
    public function destroy(string $trunk): JsonResponse  // 204
}
```

- [ ] `index`/`show` compute `in_use_by` via `OutboundWhitelist::query()->where('outbound_trunk_name', $name)->pluck('name')` (Phase 2 wires the warning UI; backend field lands now).
- [ ] Public-* guard: `update`/`destroy` fetch the trunk first; if direction is `public-inbound|public-outbound` → 403 `{"error": "public_trunk_read_only"}`.
- [ ] Cloudonix client returning `null` → 502 `{"error": "cloudonix_unavailable"}` (distinguishes outage from empty list).
- [ ] Password handling on update: only include `profile.authentication.password` in the PUT body when the request contains a non-empty `password`.

- [ ] **Tests** (`tests/Feature/Api/TrunkControllerTest.php`, `RefreshDatabase`, `Http::fake()`):
  1. owner can list; password absent from response; `read_only` true for public-* fixture entry.
  2. pbx_admin can list; pbx_user/reporter → 403; unauthenticated → 401.
  3. create happy path → 201; validation errors (bad transport, port 99999, username without password) → 422.
  4. update on public-* → 403; delete on public-* → 403.
  5. delete happy path → 204; `in_use_by` populated when an `OutboundWhitelist` row references the trunk name.
  6. Cloudonix 500 → OPBX 502 `cloudonix_unavailable`.

- [ ] `./run-tests.sh --filter=TrunkControllerTest` green → commit `feat(trunks): Trunk management API proxying Cloudonix trunk CRUD`.

**Phase 1 checkpoint**: all TrunkControllerTest + CloudonixTrunksClientTest green; pint clean; `curl` list/create/delete against dev stack with a PAT works end-to-end.

---

## Phase 2 — Delete safety UI contract

Backend `in_use_by` already lands in Phase 1/Task 4. Phase 2 is only: API doc note + frontend consumes it in the delete dialog (Phase 3). No separate backend work.

- [ ] Verify with a manual test: create whitelist entry referencing trunk name → `GET /v1/trunks` shows it in `in_use_by`.

---

## Phase 3 — Frontend page

**Files:**
- Create: `frontend/src/pages/Trunks.tsx`, `frontend/src/services/trunks.service.ts`
- Modify: `frontend/src/router.tsx` (lazy route `/ui/trunks`), `frontend/src/components/Layout/Sidebar.tsx`

- [ ] `trunks.service.ts`: `listTrunks(direction?)`, `getTrunk(id)`, `createTrunk(payload)`, `updateTrunk(id, payload)`, `deleteTrunk(id)` against `/v1/trunks`. TS types mirroring TrunkResource.
- [ ] `Trunks.tsx` modeled on `OutboundWhitelist.tsx` conventions (TanStack Query, shadcn Dialog/Table, lucide icons):
  - Table: Name | Direction (badge, color per direction) | Address (`ip:port` + transport chip) | Prefix | Auth (`KeyRound` icon when `has_credentials`) | Status | Created | Actions.
  - Direction filter tabs: All / Inbound / Outbound / Public.
  - Refresh button (matches Users/Supervisors pattern from `3c4d4b74`).
  - **Mandatory empty state** (per AGENTS.md pattern, cf. ConferenceRooms.tsx): `Network` icon `h-12 w-12 mx-auto text-muted-foreground mb-4`, "No trunks found", contextual filter message, Create CTA when unfiltered.
  - Create/Edit dialog: direction select (help text: inbound = carrier→OPBX, outbound = OPBX→world), name (disabled on edit), ip, port, transport select, prefix, collapsible Authentication (username/password/overwrite-from; password placeholder "Leave blank to keep unchanged" on edit).
  - Public trunks: row shows `Cloudonix` badge; actions column shows read-only view dialog instead of edit/delete.
  - Delete: AlertDialog; when `in_use_by.length > 0` show warning listing referencing whitelist entries ("This trunk is referenced by outbound whitelist entries: …"); 502 error surfaces as destructive toast.
- [ ] Router: `const TrunksPage = lazy(() => import('@/pages/Trunks'));` + `<Route path="/ui/trunks">` (wrap in the same role guard component OutboundWhitelist uses).
- [ ] Sidebar: `{ name: 'Trunks', href: '/ui/trunks', icon: 'codicon-server-environment', roles: ['owner', 'pbx_admin'] }` in the PBX/phone-system section near Phone Numbers.
- [ ] `cd frontend && npm run type-check && npm run lint` clean.
- [ ] Manual UI pass (Docker Vite needs `docker compose restart frontend` after edits): empty state, create inbound trunk, edit port, attempt delete on referenced trunk (warning shown), public trunk read-only.

**Phase 3 checkpoint**: visual pass in browser via agent-browser; screenshots of list/create/delete-warning.

---

## Phase 4 — GrantableResource + OPBX OpenAPI + MCP

### Task 1: GrantableResource

- [ ] Add `case TRUNKS = 'trunks';` to `app/Enums/GrantableResource.php` (slug matches route prefix `trunks.` — no alias needed).
- [ ] `./run-tests.sh --filter=ApiKeyCoverageTest` — update its expectations to include trunks routes.

### Task 2: OPBX OpenAPI spec

**Files:**
- Create: `docs/opbx-openapi/paths/trunks/index.yaml` (list+create), `docs/opbx-openapi/paths/trunks/item.yaml` (get/update/delete)
- Modify: `docs/opbx-openapi/openapi.yaml` (register `/v1/trunks` and `/v1/trunks/{trunk}`)

- [ ] operationIds: `listTrunks`, `createTrunk`, `getTrunk`, `updateTrunk`, `deleteTrunk`. Model on `docs/opbx-openapi/paths/settings/outbound-trunks.yaml`; schemas mirror `TrunkResource` (document that password is write-only, `has_credentials` in responses).

### Task 3: MCP tools

**Files:**
- Create: `mcp-server/src/tools/trunks.ts`
- Modify: `mcp-server/src/tools/index.ts` (import the module)
- Regen: `npm run generate:opbx-client` then `npm run generate:docs`

- [ ] Five tools:

```ts
defineListTool({ name: "list_trunks", title: "List voice trunks",
  description: "List Cloudonix voice trunks (inbound carry calls into the PBX, outbound carry calls to the world). public-* directions are Cloudonix-managed and read-only.",
  permission: "trunks.read",
  operation: { operationId: "listTrunks", method: "GET", path: "/v1/trunks" },
  filters: { direction: z.enum(["inbound","outbound","public-inbound","public-outbound"]).optional() } });

defineGetTool({ name: "get_trunk", title: "Get voice trunk", permission: "trunks.read",
  operation: { operationId: "getTrunk", method: "GET", path: "/v1/trunks/{trunk}" },
  pathParam: "trunk", resultKey: "trunk", description: "Get one trunk by id. Auth passwords are never returned." });

defineWriteTool({ name: "create_trunk", permission: "trunks.create",
  operation: { operationId: "createTrunk", method: "POST", path: "/v1/trunks" },
  inputSchema: z.object({ name: z.string().min(3).max(64), ip: z.string(), port: z.coerce.number().int().min(1).max(65535),
    transport: z.enum(["udp","tcp","tls"]), direction: z.enum(["inbound","outbound"]),
    prefix: z.string().max(20).optional(), username: z.string().optional(), password: z.string().optional(),
    overwrite_from: z.boolean().optional() }),
  mapArgs: (args) => ({ body: args }), resultKey: "trunk", /* title/description as above */ });

defineWriteTool({ name: "update_trunk", permission: "trunks.update",
  operation: { operationId: "updateTrunk", method: "PUT", path: "/v1/trunks/{trunk}" }, /* no name/direction; password optional */ });
defineWriteTool({ name: "delete_trunk", permission: "trunks.delete",
  operation: { operationId: "deleteTrunk", method: "DELETE", path: "/v1/trunks/{trunk}" } });
```

- [ ] `npm run validate:opbx-api` green (contract tests see the new operations).
- [ ] Smoke test against running stack with a scoped `opbxk_` key granted only `trunks.*`: `list_trunks` works, `list_extensions` → 403.

**Phase 4 checkpoint**: ApiKeyCoverageTest green; MCP contract tests green; scoped-key smoke test passes.

---

## Phase 5 — Regression

- [ ] `vendor/bin/pint --dirty`; `cd frontend && npm run lint && npm run type-check`
- [ ] `./run-tests.sh` — expect 3 known pre-existing failures only (ExtensionPasswordControllerTest ×2, operate-as profile ×1).
- [ ] Dispatch `project-regression-tester` sub-agent for the MUST-WORK paths.
- [ ] Update memory: `.my_agent/memory/settings-cloudonix.md` (trunk CRUD in CloudonixTrunksClient), new `.my_agent/memory/trunk-management.md` + `_index.md` row, `mcp-server.md` (trunks tools).
