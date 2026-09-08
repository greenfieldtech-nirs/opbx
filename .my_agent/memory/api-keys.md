# Scoped API Keys

> **Updated 2026-09-08**: coverage completed — 23 grantable resources, key auth extended to previously Sanctum-only groups, credential subroutes excluded.

Owner-created, long-lived, revocable API keys granting per-resource read/write access to business/config endpoints. Key permissions are the **only** authorization gate for key-authenticated requests — the user role model is NOT consulted for what a key may reach (a role-compat shim only satisfies downstream controller code). Branch of origin: `feature/api-token-access`.

## Design Rules (locked)
- Only **Owners** manage keys. Keys can **never** manage keys (api-keys is not a grantable resource).
- Per-resource level `read` or `write`; write implies read; read = GET/HEAD only.
- Keys never expire; revocable (soft — sets `revoked_at`).
- Only business/config endpoints are grantable (23-resource allowlist); identity/credential surfaces are never grantable.
- Enforcement matches on **route NAME prefix** (deny-by-default), with explicit prefix aliases and exclusion list.
- Key format: `opbxk_` + 40 random chars. Only sha256 hash stored in `api_keys.token`. Plaintext returned **once** on create (as `key` field) and shown once in UI.

## Grantable resources (23)
users, extensions, conference-rooms, ai-assistants, ai-assistant-providers, ring-groups, ai-assistant-load-balancers, ivr-menus, business-hours, phone-numbers, outbound-whitelist, inbound-blacklist, call-detail-records, recordings, call-tracking-campaigns (incl. nested numbers + notification settings/logs), call-tracking-analytics, call-tracking-sessions, call-tracking-ad-platform-integrations, supervisors, auto-dialer-campaigns, distribution-lists, session-updates (incl. disconnect/coach-target with write), call-notifications.

**Never grantable** (deny-by-default or explicit exclusion): api-keys.*, profile.*, settings.cloudonix.*, webphone.*, dashboard.supervisor, join-requests.*, platform.*, plus credential subroutes even under granted parents: `extensions.password`, `extensions.reset-password`, `users.password.update`, `users.embed-token.*` (via `GrantableResource::EXCLUDED_ROUTE_PREFIXES`).

## Backend

### Enums
- `app/Enums/GrantableResource.php` — allowlist (23). Helpers: `::slugs()`, `::fromRouteName()`, `::routePrefixes()` (slug→prefixes map; single source for matcher + tests). Consts: `ROUTE_PREFIX_ALIASES` (`ai-assistant.` → ai-assistant-providers; provider routes use singular prefix), `EXCLUDED_ROUTE_PREFIXES` (credential subroutes).
- `app/Enums/ApiKeyPermissionLevel.php` — `READ`/`WRITE` (string-backed). Helper: `permitsMethod(string $httpMethod)`.

### Models
- `app/Models/ApiKey.php` — implements `Authenticatable`; is its own Sanctum tokenable carrying `organization_id`. **Intentionally NOT `ScopedBy(OrganizationScope)`** (cross-org protection is via `ApiKeyPolicy` org-match). **Role-compat shim**: `isOwner()`=true, `role`=OWNER — a write key can therefore pass owner-level policy gates (e.g. session disconnect, campaign archive). This is intentional: a granted key IS an org-owner-equivalent machine credential for its resources.
- `app/Models/ApiKeyPermission.php` — belongsTo ApiKey; `resource` (slug) + `level`.
- `database/factories/ApiKeyFactory.php`.

### Migrations
- `api_keys` (token=sha256 hash, `organization_id`, `name`, `last_used_at`, `revoked_at`), `api_key_permissions` (resource, level).

### Service
- `app/Services/ApiKeyService.php` — `create()`, `resolve()`, `replacePermissions()` (all atomic / `DB::transaction`). `const PREFIX = 'opbxk_'`.

### Middleware (order matters)
- `app/Http/Middleware/ResolveApiKey.php` (alias `resolve.api.key`) — authenticates `opbxk_` bearer tokens, **seeds `Auth::guard($g)->setUser($apiKey)`** for each guard in `config('sanctum.guard')` (=`['web']`), throttles `last_used_at` writes to 1/5s per key (absolute diff), EXCEPT `users`/`extensions` (write every request). Priority-listed **before** `AuthenticatesRequests` (via `prependToPriorityList`).
- `app/Http/Middleware/EnforceApiKeyScope.php` (alias `enforce.api.key.scope`) — the real key-authz. Deny-by-default scope gate: maps route name → GrantableResource → required level; 403 if not permitted. **Must NOT be priority-listed** (must keep group position after `auth:sanctum`, else `user()` is null and scope is silently bypassed). NOTE: `SubstituteBindings` runs before it — nonexistent ids 404 before the scope check (expected; tests must create targets).
- Non-grantable route (e.g. api-keys itself) → `fromRouteName`=null → 403. This is what enforces "keys can't manage keys."

### Policy / Provider
- `app/Policies/ApiKeyPolicy.php` — owner-only + org-match. Has dead-code `before()` deny (Gate::before for ApiKey short-circuits it; kept belt-and-suspenders). show/update/destroy return 403 for cross-org (existence-disclosure accepted; opaque int ids).
- `app/Providers/AppServiceProvider.php` — `Gate::before` returns `true` for ApiKey (null otherwise); `Gate::policy(ApiKey::class, ApiKeyPolicy::class)`; Sanctum `usePersonalAccessTokenModel`.

### Controller / Requests / Resource
- `app/Http/Controllers/Api/ApiKeyController.php` — `index/store/show/update/destroy/grantableResources`. store/update call explicit `$this->authorize()`, use atomic combined update.
- `app/Http/Requests/ApiKey/StoreApiKeyRequest.php`, `UpdateApiKeyRequest.php` — update `name` is `sometimes|required` (rejects blank); `permissions` `min:1` when present.
- `app/Http/Resources/ApiKeyResource.php` — never exposes token.

### Route groups with key support (`routes/api.php`)
All tenant business groups now carry `resolve.api.key` + `enforce.api.key.scope`:
- Main group (~line 287): `['resolve.api.key','auth:sanctum','tenant.scope','operate.as','rate_limit_org:api','enforce.api.key.scope']`
- Session updates group: `['resolve.api.key','auth:sanctum','tenant.scope','enforce.api.key.scope']` (no rate limit, real-time)
- Call notifications group: `['resolve.api.key','auth:sanctum','tenant.scope','rate_limit_org:api','enforce.api.key.scope']`
- Auto-dialer group (~line 524): `['resolve.api.key','auth:sanctum','tenant.scope','enforce.api.key.scope']` (no rate limit, monitor polling)
`Route::get('api-keys/grantable-resources')` registered **before** `Route::apiResource('api-keys')`.

### API contract (paths relative to `/api/v1`)
- `GET /api-keys` → `{ data: ApiKey[] }`
- `GET /api-keys/grantable-resources` → `{ data: string[] }` (dynamic from enum — UI auto-renders new resources)
- `POST /api-keys` `{ name, permissions:[{resource,level}] }` → 201 `{ data, key }` (`key` = one-time plaintext)
- `PUT /api-keys/{id}` `{ name?, permissions? }` → `{ data }`
- `DELETE /api-keys/{id}` → 200 `{ message }` (soft revoke)

### bootstrap/app.php
Aliases `resolve.api.key`, `enforce.api.key.scope`. Contains the priority-list constraint comment (do NOT priority-list EnforceApiKeyScope).

## Frontend (`frontend/`)
- `src/services/apiKeys.service.ts` — `apiKeysService` object; fetches grantable slugs dynamically from the API (new resources appear automatically).
- `src/components/settings/ApiKeyPermissionBuilder.tsx` — per-resource none/read/write segmented selector (dynamic).
- `src/pages/ApiKeysSettings.tsx` — owner-only. Route `/ui/api-keys` in `src/router.tsx` (`<OwnerRoute>`). Sidebar item in `src/components/Layout/Sidebar.tsx` (roles: ['owner']).

## Tests
- `tests/Feature/ApiKey/`: ResolveApiKeyMiddlewareTest, EnforceApiKeyScopeTest, ApiKeyRoleCompatTest, ApiKeyPolicyTest, ApiKeyControllerTest, ApiKeyIsolationTest, **ApiKeyCoverageTest** (new resources grantable, exclusions deny credential subroutes, previously Sanctum-only groups accept keys, route-coverage drift guard).
- `tests/Unit/Enums/GrantableResourceTest.php` — slugs, prefix matching, aliases, exclusions, slug→route drift guard (uses `routePrefixes()`).
- `tests/Unit/Services/ApiKeyServiceTest.php`.
- 64 ApiKey+enum tests green. (`/tests` is gitignored — commit test files with `git add -f`.)

## Principal-type compatibility (2026-09-08)

Key-authenticated requests pass an `ApiKey` (not `User`) as the authenticated principal. Code consuming `$request->user()` must not hard-type `User`. Fixed sinks: `IvrAudioConfig::fromRequest`/`resolveRecordingUrl` (User|ApiKey|null), `UserInvitationService::invite` + duplicate notification (User|ApiKey), `CallDetailRecordController` statistics helpers (User|ApiKey). **Recordings creation is deliberately 403 for keys** (`created_by` FK to users is non-nullable). Dead code noted: `ValidatesTenantScope::validateUserTenantScope(Request, User)` has no callers. When adding key-reachable endpoints, never type-hint the principal as `User` — use `User|ApiKey` or `$request->user()` without hints.

## Known Issues / Notes
- Frontend `npm run lint` is broken **repo-wide** (no eslint config ever committed) — pre-existing. `npm run type-check` is the correctness gate.
- Pre-existing unrelated failures (NOT caused by this work, verified via stash): `ExtensionPasswordControllerTest` reset-password tests (2), operate-as self-mutating profile test (1).
- IVR menus have NO policy (any role can mutate) — key scope is the only gate for key callers; user callers remain ungated upstream.

## Backend

### Enums
- `app/Enums/GrantableResource.php` — allowlist (12): `users, extensions, conference-rooms, ai-assistants, ring-groups, ai-assistant-load-balancers, business-hours, phone-numbers, outbound-whitelist, inbound-blacklist, call-detail-records, recordings`. Helpers: `::slugs()`, `::fromRouteName()`.
- `app/Enums/ApiKeyPermissionLevel.php` — `READ`/`WRITE` (string-backed). Helper: `permitsMethod(string $httpMethod)`.

### Models
- `app/Models/ApiKey.php` — implements `Authenticatable`; is its own Sanctum tokenable carrying `organization_id`. **Intentionally NOT `ScopedBy(OrganizationScope)`** (cross-org protection is via `ApiKeyPolicy` org-match). Methods: `levelForResource()`, `isRevoked()`. **Role-compat shim** (so key requests satisfy downstream User role-method calls): `isOwner()`=true, `role` accessor=`OWNER`, `hasRole()`=owner-only; `isPBXAdmin/isPBXUser/isSupervisor/isReporter`=false; `getIsPlatformManagerAttribute`=false.
- `app/Models/ApiKeyPermission.php` — belongsTo ApiKey; `resource` (slug) + `level`.
- `database/factories/ApiKeyFactory.php`.

### Migrations
- `api_keys` (token=sha256 hash, `organization_id`, `name`, `last_used_at`, `revoked_at`), `api_key_permissions` (resource, level).

### Service
- `app/Services/ApiKeyService.php` — `create()`, `resolve()`, `replacePermissions()` (all atomic / `DB::transaction`). `const PREFIX = 'opbxk_'`.

### Middleware (order matters)
- `app/Http/Middleware/ResolveApiKey.php` (alias `resolve.api.key`) — authenticates `opbxk_` bearer tokens, **seeds `Auth::guard($g)->setUser($apiKey)`** for each guard in `config('sanctum.guard')` (=`['web']`), throttles `last_used_at` writes to 1/5s per key (absolute diff), EXCEPT `users`/`extensions` (write every request). Priority-listed **before** `AuthenticatesRequests` (via `prependToPriorityList`).
- `app/Http/Middleware/EnforceApiKeyScope.php` (alias `enforce.api.key.scope`) — the real key-authz. Deny-by-default scope gate: maps route name → GrantableResource → required level; 403 if not permitted. **Must NOT be priority-listed** (must keep group position after `auth:sanctum`, else `user()` is null and scope is silently bypassed). Non-grantable route (e.g. api-keys itself) → `fromRouteName`=null → 403. This is what enforces "keys can't manage keys."

### Policy / Provider
- `app/Policies/ApiKeyPolicy.php` — owner-only + org-match. Has dead-code `before()` deny (Gate::before for ApiKey short-circuits it; kept belt-and-suspenders). show/update/destroy return 403 for cross-org (existence-disclosure accepted; opaque int ids).
- `app/Providers/AppServiceProvider.php` — `Gate::before` returns `true` for ApiKey (null otherwise); `Gate::policy(ApiKey::class, ApiKeyPolicy::class)`; Sanctum `usePersonalAccessTokenModel`.

### Controller / Requests / Resource
- `app/Http/Controllers/Api/ApiKeyController.php` — `index/store/show/update/destroy/grantableResources`. store/update call explicit `$this->authorize()`, use atomic combined update.
- `app/Http/Requests/ApiKey/StoreApiKeyRequest.php`, `UpdateApiKeyRequest.php` — update `name` is `sometimes|required` (rejects blank); `permissions` `min:1` when present.
- `app/Http/Resources/ApiKeyResource.php` — never exposes token.

### Routes (`routes/api.php`, protected group ~line 254)
Group middleware: `['resolve.api.key','auth:sanctum','tenant.scope','rate_limit_org:api','enforce.api.key.scope']`.
`Route::get('api-keys/grantable-resources')` registered **before** `Route::apiResource('api-keys')->parameters(['api-keys' => 'apiKey'])` (default param would be `api_key`; overridden so controllers type-hint `ApiKey $apiKey`).

### API contract (paths relative to `/api/v1`)
- `GET /api-keys` → `{ data: ApiKey[] }`
- `GET /api-keys/grantable-resources` → `{ data: string[] }`
- `POST /api-keys` `{ name, permissions:[{resource,level}] }` → 201 `{ data, key }` (`key` = one-time plaintext)
- `PUT /api-keys/{id}` `{ name?, permissions? }` → `{ data }`
- `DELETE /api-keys/{id}` → 200 `{ message }` (soft revoke)

### bootstrap/app.php
Aliases `resolve.api.key`, `enforce.api.key.scope`. Contains the priority-list constraint comment (do NOT priority-list EnforceApiKeyScope).

## Frontend (`frontend/`)
- `src/services/apiKeys.service.ts` — `apiKeysService` object (`list/grantableResources/create/update/revoke`); `import api from './api'`; paths without `/v1`. Exports `ApiKey`, `ApiKeyPermission`, request/response types.
- `src/hooks/useApiKeys.ts` — TanStack Query, queryKey `['api-keys']`, invalidates on create/revoke; `sonner` toasts.
- `src/components/settings/ApiKeyPermissionBuilder.tsx` — per-resource none/read/write segmented selector (Button-based; repo has no ToggleGroup). Humanizes slugs.
- `src/pages/ApiKeysSettings.tsx` — owner-only (`user?.role === 'owner'`). Create dialog + **separate non-dismissible reveal dialog** for one-time plaintext key (held in local state, `navigator.clipboard`, `onInteractOutside` prevented). List table (name/permissions/last used/status/revoke via AlertDialog confirm). Empty state via `@/components/design-system/EmptyState`.
- Route `/ui/api-keys` in `src/router.tsx` (lazy import, wrapped in `<OwnerRoute>`).
- Sidebar item in `src/components/Layout/Sidebar.tsx`: `{ name: 'API Keys', href: '/ui/api-keys', icon: 'codicon-key', roles: ['owner'] }`.

## Tests
- `tests/Feature/ApiKey/`: ResolveApiKeyMiddlewareTest, EnforceApiKeyScopeTest, ApiKeyRoleCompatTest, ApiKeyPolicyTest, ApiKeyControllerTest, ApiKeyIsolationTest.
- `tests/Unit/Services/ApiKeyServiceTest.php`.
- 50 ApiKey tests green. (`/tests` is gitignored — commit test files with `git add -f`.)

## Principal-type compatibility (2026-09-08)

Key-authenticated requests pass an `ApiKey` (not `User`) as the authenticated principal. Code consuming `$request->user()` must not hard-type `User`. Fixed sinks: `IvrAudioConfig::fromRequest`/`resolveRecordingUrl` (User|ApiKey|null), `UserInvitationService::invite` + duplicate notification (User|ApiKey), `CallDetailRecordController` statistics helpers (User|ApiKey). **Recordings creation is deliberately 403 for keys** (`created_by` FK to users is non-nullable). Dead code noted: `ValidatesTenantScope::validateUserTenantScope(Request, User)` has no callers. When adding key-reachable endpoints, never type-hint the principal as `User` — use `User|ApiKey` or `$request->user()` without hints.

## Known Issues / Notes
- Frontend `npm run lint` is broken **repo-wide** (no eslint config ever committed) — pre-existing, not this feature. `npm run type-check` is the correctness gate and passes clean.
- Pre-existing unrelated bug (NOT fixed): `StoreRingGroupRequest` doesn't require `name` → empty POST 500s on insert.
