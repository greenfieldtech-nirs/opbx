## 9. Security Considerations

This section enumerates the security properties that the Platform Manager feature **must** uphold. Each subsection identifies a threat surface, the controls in place, and any residual risk.

### 9.1 Authentication & Authorization Layers

Platform Manager endpoints are protected by **three independent layers**, each of which must pass before any cross-tenant operation executes:

| Layer | Mechanism | Failure Mode |
|-------|-----------|--------------|
| **L1 — Authentication** | Laravel Sanctum (session cookie or Bearer token) | 401 Unauthenticated |
| **L2 — Platform Authorization** | `EnsurePlatformManager` middleware checks `$user->is_platform_manager === true` | 403 Forbidden |
| **L3 — Scope Bypass** | `OrganizationScope::bypass()` wraps individual operations | Query returns empty set (fail-closed) |

- [ ] [PM-9.1.1] Verify that removing **any single layer** causes the request to fail with the documented HTTP status
- [ ] [PM-9.1.2] Verify that an authenticated non-PM user hitting `/api/v1/platform/*` receives exactly `403` with a JSON error body — never a 500 or a data leak
- [ ] [PM-9.1.3] Verify that an unauthenticated request to any platform endpoint receives `401` **before** the platform middleware is evaluated

> **Implementation Note:** The middleware stack order in `routes/platform.php` MUST be `auth:sanctum` → `platform.manager`. Reversing the order would expose the `is_platform_manager` check to unauthenticated requests, which is a timing-oracle risk.

### 9.2 Mass Assignment Protection

The `is_platform_manager` column is a **privilege escalation vector** if it can be set through mass assignment.

**Controls:**

1. `is_platform_manager` is explicitly **excluded** from the `User` model's `$fillable` array
2. The column can only be set via:
   - Artisan commands (`opbx:create-platform-manager`, `opbx:set-platform-manager`)
   - The dedicated `PlatformUserController::setPlatformManager()` endpoint which uses direct Eloquent assignment (`$user->is_platform_manager = true; $user->save();`)
3. No API endpoint — including platform endpoints — accepts `is_platform_manager` as a general request parameter
4. `PlatformUpdateUserRequest` form request explicitly strips the field via validation rules even if submitted

- [ ] [PM-9.2.1] Unit test: `User::create(['is_platform_manager' => true, ...])` must NOT set the flag
- [ ] [PM-9.2.2] Unit test: `$user->fill(['is_platform_manager' => true])` must NOT set the flag
- [ ] [PM-9.2.3] Unit test: `$user->update(['is_platform_manager' => true])` must NOT set the flag
- [ ] [PM-9.2.4] Feature test: POST/PUT to any normal user-facing endpoint with `is_platform_manager=true` in the payload must be silently ignored
- [ ] [PM-9.2.5] Verify `is_platform_manager` does NOT appear in any `$fillable`, `$guarded = []` override, or `unguard()` call outside of test setup

### 9.3 Scope Bypass Safety

The `OrganizationScope` bypass is the **most security-critical mechanism** in this feature. A leaked or stuck bypass would expose all tenant data to a single request context.

**Design Constraints:**

| Property | Implementation | Rationale |
|----------|---------------|-----------|
| Counter-based, not boolean | `private static int $bypassCount` incremented/decremented | Supports nested bypass calls without premature re-enable |
| Exception-safe | All bypass blocks use `try/finally` | Counter is decremented even on exception |
| Never globally disabled | Bypass only active while `$bypassCount > 0` | Scope re-engages immediately after the bypass block |

- [ ] [PM-9.3.1] Verify that `bypass()` increments counter before callback and decrements in `finally`
- [ ] [PM-9.3.2] Verify that an exception inside the callback still decrements the counter
- [ ] [PM-9.3.3] Verify that nested `bypass()` calls correctly maintain the counter (counter reaches 2, then back to 1, then 0)
- [ ] [PM-9.3.4] Verify that after `bypass()` returns, a normal query on a scoped model returns **only** the authenticated user's organization data
- [ ] [PM-9.3.5] Verify that the `$bypassCount` property is `private` with no public setter

> **CRITICAL:** The bypass counter is a **per-process, per-request** static property. In PHP-FPM (one request per worker), this is inherently safe. If the application ever moves to a coroutine-based server (e.g., Octane with Swoole), this mechanism MUST be refactored to use request-scoped storage.

### 9.4 Token Ability Separation

Platform API tokens carry elevated abilities that are scoped and validated independently.

**Platform Abilities:**

| Ability | Grants |
|---------|--------|
| `platform:read` | Read-only access to cross-org data |
| `platform:write` | Modify organizations and users cross-tenant |
| `platform:manage-users` | Cross-org user CRUD, PM flag management |
| `platform:manage-organizations` | Organization status transitions, settings |
| `platform:audit-logs` | Read audit trail |

- [ ] [PM-9.4.1] Tokens created for non-PM users MUST NOT include any `platform:*` abilities
- [ ] [PM-9.4.2] **CRITICAL:** When PM flag is revoked via `opbx:revoke-platform-manager` command or API, all user's Sanctum tokens MUST be immediately deleted via `$user->tokens()->delete()`. This forces re-authentication and prevents continued access with stale platform abilities.
- [ ] [PM-9.4.3] Token abilities must be validated server-side; the frontend must never rely solely on cached ability lists
- [ ] [PM-9.4.4] The `revokeAllTokens()` method on User model uses `$this->tokens()->delete()` to revoke all Sanctum tokens

### 9.5 Audit Trail Completeness

The `PlatformAuditService` is the **non-bypassable** logging layer for all cross-tenant operations.

**Audit Guarantees:**

1. Every mutating platform action is audit-logged **before** the mutation commits
2. Every record includes: `platform_manager_user_id`, `action`, `target_organization_id`, `target_entity_type`, `target_entity_id`, `before_state`, `after_state`, `reason`, `ip_address`, `user_agent`
3. If audit logging fails, the **entire operation** must abort (wrap in DB transaction)

- [ ] [PM-9.5.1] Verify that every platform controller mutation method calls `PlatformAuditService::log()`
- [ ] [PM-9.5.2] Verify that a database failure during audit logging causes a rollback of the primary operation
- [ ] [PM-9.5.3] Verify that `before_state` and `after_state` are captured as JSON snapshots of the affected model

### 9.6 Rate Limiting

Platform endpoints are rate-limited independently at 120 requests per minute per user.

- [ ] [PM-9.6.1] The `platform` rate limiter is applied to the `/api/v1/platform/` route group
- [ ] [PM-9.6.2] Rate limit is **per-user**, falling back to per-IP for edge cases
- [ ] [PM-9.6.3] Exceeding the limit returns `429 Too Many Requests` with `Retry-After` header
- [ ] [PM-9.6.4] Rate limit is configurable via environment variable: `PLATFORM_RATE_LIMIT=120`

### 9.7 Input Validation

- [ ] [PM-9.7.1] Every platform endpoint has a corresponding `FormRequest` — no inline `$request->validate()` calls
- [ ] [PM-9.7.2] No `FormRequest` accepts `is_platform_manager` as a valid field
- [ ] [PM-9.7.3] Validation errors return `422` with structured JSON matching Laravel's default error format

### 9.8 Frontend Route Guard

The `PlatformManagerRoute` component is a **UX convenience, not a security boundary**.

- [ ] [PM-9.8.1] `PlatformManagerRoute` checks `user.is_platform_manager` from the auth context and redirects to `/ui/dashboard` if `false`
- [ ] [PM-9.8.2] Direct URL access to `/ui/platform/*` by a non-PM user shows a redirect — never a flash of platform content
- [ ] [PM-9.8.3] **Security boundary is the API**: even if the frontend guard is bypassed, all API calls return `403`

### 9.9 Organization Suspension Enforcement

When an organization is suspended via the platform management API, all users in that organization must be immediately blocked from accessing the system.

**Implementation:**

1. The `EnsureTenantScope` middleware (or equivalent existing middleware) checks the authenticated user's organization status
2. If `organization.status === 'suspended'`, return **403 Forbidden** with message: `"Your organization has been suspended."`
3. This check runs AFTER authentication but BEFORE tenant scope validation
4. Platform managers bypass this check entirely (they bypass all tenant-scoped middleware)

- [ ] [PM-9.9.1] Verify that users from suspended organizations receive 403 on all API endpoints
- [ ] [PM-9.9.2] Verify that the 403 response includes the exact message "Your organization has been suspended."
- [ ] [PM-9.9.3] Verify that platform managers are NOT blocked by this check (they bypass tenant scope)
- [ ] [PM-9.9.4] Verify that activating a suspended organization immediately restores access

### 9.10 Cross-Site Protections

Existing protections apply to platform endpoints:

| Protection | Mechanism |
|-----------|-----------|
| CSRF | Sanctum cookie-based sessions use `XSRF-TOKEN` |
| CORS | `config/cors.php` whitelist; no additional origins needed |
| XSS | React's default escaping + existing CSP headers |
| SQL Injection | Eloquent parameterized queries only |

### 9.10 Future Security Recommendations

Not in scope for v1.0.0 but recommended:

1. **IP whitelisting for platform endpoints** — Restrict `/api/v1/platform/*` to configurable CIDR ranges
2. **Two-factor authentication (2FA) enforcement** — Require PM users to have 2FA enabled
3. **Shorter session timeout** — PM sessions should have shorter idle timeout (e.g., 15 minutes)
4. **Audit log integrity** — Hash-chain audit records for tamper detection

---

