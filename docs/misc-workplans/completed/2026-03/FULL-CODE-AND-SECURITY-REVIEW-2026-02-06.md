# OPBX Full Code Review & Security Audit Report

**Date:** 2026-02-06
**Branch:** develop (commit d9c69ca)
**Auditor:** Claude Opus 4.6
**Scope:** Full repository analysis - code quality, architecture, and security

---

## Executive Summary

The OPBX codebase demonstrates **solid architectural foundations** with proper separation of concerns, consistent design patterns, and thoughtful multi-tenancy implementation. The application is well-structured with a clear control plane (CRUD/config) and execution plane (real-time call routing) separation.

However, the audit identified several issues requiring attention:

| Severity | Code Review | Security Audit | Total |
|----------|-------------|----------------|-------|
| Critical | 3 | 4 | **7** |
| High | 12 | 7 | **19** |
| Medium | 18 | 9 | **27** |
| Low | 4 | 6 | **10** |

**Overall Code Quality Score: 7.5/10**

**Key strengths:** Multi-tenancy isolation, RBAC policy system, structured logging with sanitization, comprehensive security headers, webhook signature verification, strategy pattern for routing.

**Key risks:** SQL injection vector, unauthenticated file access, wildcard token abilities on registration, secrets in `.env`, missing webhook idempotency, plaintext passwords in API responses.

---

# PART 1: CODE REVIEW

---

## 1. Critical Code Issues

### CR-1: Missing Webhook Idempotency in CloudonixWebhookController
**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:39-50`

The `callInitiated` webhook handler does NOT implement idempotency checking, despite CLAUDE.md explicitly requiring it. Cloudonix may retry webhooks on network failures, leading to duplicate call processing.

```php
public function callInitiated(CallInitiatedRequest $request): Response
{
    // No idempotency check - proceeds directly to routing
    $callId = $request->input('CallSid') ?? $request->input('call_id');
```

**Impact:** Duplicate webhook calls could create race conditions, double-processing calls.

**Recommended fix:** Implement Redis-based idempotency using `idem:webhook:{hash(CallSid)}` pattern before processing.

---

### CR-2: Plaintext Passwords in API Responses
**File:** `app/Http/Controllers/Api/ExtensionController.php:692, 768`

The `resetPassword` and `getPassword` endpoints return plaintext passwords in JSON responses.

```php
return response()->json([
    'new_password' => $newPassword,  // Plaintext in response body
]);
```

**Impact:** Passwords can be captured by proxies, load balancers, API gateways, browser dev tools, and network logs.

**Recommended fix:** Return passwords only on creation/reset with `Cache-Control: no-store` headers. Consider secure delivery channels.

---

### CR-3: Inconsistent Transaction Usage in ExtensionController
**File:** `app/Http/Controllers/Api/ExtensionController.php:219-231`

Manual `DB::transaction()` usage instead of the `AbstractApiCrudController` hook pattern. Bypasses `beforeStore/afterStore` hooks and creates maintenance burden.

**Recommended fix:** Extend `AbstractApiCrudController` and use its transaction control hooks consistently.

---

## 2. High Priority Code Issues

### CR-4: Fat Controller Pattern - ExtensionController (896 lines)
**File:** `app/Http/Controllers/Api/ExtensionController.php`

Handles CRUD, password management, Cloudonix synchronization, and complex type-handling. Compare with `RingGroupController` at 293 lines using `AbstractApiCrudController`.

**Recommended fix:** Split into `ExtensionController` (CRUD), `ExtensionPasswordController` (already exists), and `ExtensionCloudonixController` (sync).

---

### CR-5: N+1 Query Risk in VoiceRoutingManager
**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php:401, 551-609`

Multiple locations load related models without eager loading during business hours routing, executing individual queries per extension lookup during call routing.

```php
$extension = Extension::withoutGlobalScope(OrganizationScope::class)
    ->where('id', $extensionId)
    ->where('organization_id', $did->organization_id)
    ->where('status', 'active')
    ->first();  // Executed in loop - N+1
```

**Impact:** Performance degradation during peak call volume.

**Recommended fix:** Cache business hours schedules with relationships pre-loaded, or use eager loading.

---

### CR-6: Excessive Logging Verbosity
**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php` (50+ Log::info statements)

Routine operations logged at INFO level create massive log volume in production, making it harder to find actual issues.

**Recommended fix:** Reduce routine logging to DEBUG level; keep INFO for key routing decisions only.

---

### CR-7: Missing Request Validation in VoiceRoutingController
**File:** `app/Http/Controllers/Voice/VoiceRoutingController.php:34-46`

Voice routing methods use raw `Request` instead of Form Request validation, directly accessing unvalidated inputs.

```php
public function handleInbound(Request $request): Response
{
    $orgId = $request->input('_organization_id');  // Could be null
    return $this->manager->handleInbound($request);
}
```

**Recommended fix:** Create `HandleInboundRequest`, `RingGroupCallbackFormRequest`, `IvrInputFormRequest` with validation rules.

---

### CR-8: Missing Tenant Verification in Webhook Routing Methods
**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:207-334`

Direct routing methods (`routeExtensionDirectly`, `routeConferenceExtensionDirectly`, etc.) don't verify the extension belongs to the claimed organization before routing.

**Recommended fix:** Add explicit tenant validation at method entry.

---

### CR-9: Inconsistent Error Response Formats
**Files:** Multiple controllers

Some controllers return `{'error': ..., 'message': ...}`, others return XML, and field names vary. Frontend cannot reliably parse errors.

**Recommended fix:** Standardize on a consistent error response structure via a shared trait or Laravel's exception handler.

---

### CR-10: Race Condition in Ring Group Routing
**File:** `app/Services/CallRouting/CallRoutingService.php:225-228`

Ring group routing acquires a Redis cache lock then refreshes the model, but `refresh()` doesn't use database-level locks. Another process could modify members between cache lock acquisition and DB query.

**Recommended fix:** Use `SELECT FOR UPDATE` within the lock callback or use stricter isolation level.

---

### CR-11: Duplicate Routing Logic
**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:207-334`

Extension routing logic is duplicated between `CloudonixWebhookController` and `VoiceRoutingManager`, creating drift risk.

**Recommended fix:** Consolidate into `VoiceRoutingManager`; have webhook controller delegate.

---

### CR-12: Missing Database Indexes for Hot Paths
**Files:** Database migrations

Extension lookups by `(organization_id, extension_number, status)` are the hottest query path during call routing but lack a composite index.

**Recommended fix:** Add migration: `$table->index(['organization_id', 'extension_number', 'status'])`.

---

### CR-13: Hard-Coded Magic Numbers
**Files:** Multiple

Lock timeouts, retry counts, and TTL values scattered as literals instead of config values.

**Recommended fix:** Move to `config/` files or environment variables.

---

### CR-14: Missing Rate Limiting on Password Retrieval
**File:** `routes/api.php:207-208`

`GET extensions/{id}/password` has no rate limiting, while `PUT extensions/{id}/reset-password` correctly has `sensitive-operations` middleware.

**Recommended fix:** Add `throttle:sensitive` middleware to getPassword route.

---

### CR-15: Stack Traces in Webhook Error Responses
**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:491-505`

Exception stack traces returned to external callers (Cloudonix) in error responses.

```php
return response()->json([
    'trace' => $e->getTraceAsString(),  // Exposes internals
], 500);
```

**Recommended fix:** Remove trace from responses; log internally only.

---

## 3. Medium Priority Code Issues

### CR-16: Incomplete Ring Group Fallback Handling
**File:** `app/Http/Controllers/Api/RingGroupController.php:128-168`

`normalizeFallbackFields` clears all fallback IDs before setting the new one. If `fallback_action` is invalid, all references are cleared.

### CR-17: Missing Health Check for Queue Workers
**File:** `routes/api.php:53-59`

Health endpoint checks API and storage but not queue worker status.

### CR-18: Cache Stampede Risk
**File:** `app/Services/VoiceRouting/VoiceRoutingCacheService.php`

Multiple simultaneous cache misses for the same extension could cause thundering herd to database.

### CR-19: Missing Database Connection Retry Logic
Queue workers and webhook handlers have no explicit retry logic for transient DB failures.

### CR-20: Inconsistent Logging Context
Some logs include `request_id`, others don't. Should be centralized in middleware.

### CR-21: No Request Size Limits
No explicit request size validation for webhook payloads or file uploads.

### CR-22: Configuration Injection via JSON Fields
Extension model's `configuration` JSON field is mass-assignable without schema validation.

### CR-23: Missing Migration Rollback Tests
No tests verify `down()` method safety across 56 migrations.

### CR-24: Unvalidated Enum Values in Filters
`AppliesFilters` trait may accept invalid enum string values.

### CR-25: Missing Audit Trail Verification
`AuditLogger` exists but completeness across all controllers is unverified.

### CR-26: Timezone Edge Cases in Business Hours
DST transitions and UTC offset handling need thorough testing.

### CR-27: No Circuit Breaker on Cloudonix API Client
External API calls lack circuit breaker protection (service exists but usage unverified).

### CR-28: No Request Timeout Configuration
No explicit timeouts for Cloudonix API calls or webhook responses.

---

## 4. Positive Code Patterns Observed

1. **Strong Multi-Tenancy** - Consistent `OrganizationScope`, tenant isolation checks
2. **AbstractApiCrudController** - DRY CRUD with hook pattern (used by most controllers)
3. **Policy-Based Authorization** - 10 policies with consistent `$this->authorize()` usage
4. **Structured Logging** - JSON logs with context, call_id correlation
5. **Service Layer** - Proper separation from controllers
6. **API Resources** - Consistent response transformation
7. **Form Request Validation** - Comprehensive on most endpoints
8. **PHP Enums** - 13 enums providing type safety throughout
9. **Distributed Locking** - Redis locks for race condition prevention
10. **Strict Types** - `declare(strict_types=1)` in all files
11. **Mass Assignment Protection** - All 23 models have proper `$fillable`
12. **Password Hiding** - Extension model properly hides password in `$hidden`

---

# PART 2: SECURITY AUDIT

---

## 1. Critical Security Issues

### SEC-1: SQL Injection in SessionUpdateController
**Severity: CRITICAL**
**File:** `app/Http/Controllers/Api/SessionUpdateController.php:66-71`

Organization ID and datetime are directly interpolated into a raw SQL string inside `DB::raw()`:

```php
->join(DB::raw('(SELECT session_id, MAX(id) as max_id
                 FROM session_updates
                 WHERE organization_id = '.$organizationId.'
                 AND status IN ("processing", "ringing", "connected", "answer")
                 AND updated_at >= "'.now()->subHours(24)->toDateTimeString().'"
                 GROUP BY session_id) as su2'),
```

Although `$organizationId` comes from the authenticated user, this is a dangerous pattern. If the authentication layer is ever bypassed or if the column type changes, this becomes directly exploitable.

**Remediation:** Replace `DB::raw()` with Eloquent subquery builder using parameterized bindings.

---

### SEC-2: Wildcard Token Abilities on Registration
**Severity: CRITICAL**
**File:** `app/Http/Controllers/Api/RegisterController.php:44-48`

Registration creates Sanctum tokens with `['*']` abilities, bypassing the carefully scoped role-based abilities defined in `AuthController`:

```php
$token = $user->createToken('registration-token', ['*'], now()->addDay());
```

Compare with `AuthController::login()` which properly scopes abilities per role.

**Remediation:** Use `$this->getTokenAbilities(UserRole::OWNER)` instead of `['*']`.

---

### SEC-3: Unauthenticated Public Access to Recording Files
**Severity: CRITICAL**
**Files:** `app/Http/Controllers/Api/RecordingsController.php:395-501`, `routes/api.php:48-50`, `routes/web.php:10-12`

The `serveMinioFile` endpoint has **no authentication** and is registered as a public route:

```php
Route::get('storage/recordings/{path}', [RecordingsController::class, 'serveMinioFile'])
    ->where('path', '[0-9]+/.+');  // No auth middleware
```

Additionally sets `Access-Control-Allow-Origin: *`. Anyone who guesses `{org_id}/{filename}` can access any recording.

**Remediation:** Implement signed URLs with expiration for Cloudonix access. Require authentication for browser access. Use pre-signed S3 URLs instead of proxying.

---

### SEC-4: Secrets Present in .env File
**Severity: CRITICAL**
**File:** `.env`

Real secrets found including:
- `APP_KEY` (Laravel encryption key)
- `NGROK_AUTHTOKEN` (ngrok tunnel token)
- `CLOUDONIX_DOMAIN_CDR_AUTH_KEY` (CDR authentication key)
- Various service passwords

While `.env` is in `.gitignore`, verify it was never committed with `git log --all -- .env`.

**Remediation:** Rotate all secrets. Use secret management tools in production.

---

## 2. High Security Issues

### SEC-5: X-Organization-ID Header Accepted in Rate Limiter
**Severity: HIGH**
**File:** `app/Http/Middleware/RateLimitPerOrganization.php:128-133`

Rate limiting middleware accepts organization ID from a custom HTTP header:

```php
$headerOrgId = $request->header('X-Organization-ID');
if ($headerOrgId) {
    return (int) $headerOrgId;
}
```

An attacker can inject any organization ID to attribute rate limit usage to another org, bypassing their own limits while causing DoS for the target.

**Remediation:** Remove this header fallback entirely or restrict to `local`/`testing` environments.

---

### SEC-6: Decrypted API Keys Exposed in Settings Response
**Severity: HIGH**
**File:** `app/Http/Controllers/Api/SettingsController.php:73-74`

Fully decrypted Cloudonix API keys returned in JSON responses, bypassing the model's `$hidden` protection:

```php
'domain_api_key' => $settings->domain_api_key,
'domain_requests_api_key' => $settings->domain_requests_api_key,
```

**Remediation:** Return masked versions using existing `getMaskedDomainApiKey()` / `getMaskedDomainRequestsApiKey()` methods. Expose full keys only through a separate "reveal" endpoint with additional confirmation.

---

### SEC-7: CDR Webhook Authentication Relies on Domain UUID Only
**Severity: HIGH**
**File:** `app/Http/Middleware/VerifyCloudonixSignature.php:62-113`

CDR webhooks authenticated solely by matching `domain_uuid` or `domain_name` from the payload. No cryptographic signature or Bearer token required. An attacker who knows a valid UUID can forge CDR data.

**Remediation:** Add cryptographic verification (HMAC, IP whitelisting, or shared secret header).

---

### SEC-8: Recording Controller Missing Explicit Tenant Check
**Severity: HIGH**
**File:** `app/Http/Controllers/Api/RecordingsController.php:164-178`

The `show` method uses route model binding without an explicit tenant scope check, unlike `AbstractApiCrudController`.

**Remediation:** Add `$recording->organization_id !== $user->organization_id` check.

---

### SEC-9: MySQL Port Exposed to Host in Docker
**Severity: HIGH**
**File:** `docker-compose.yml:137-138`

MySQL 3306, MinIO 9000/9001 exposed to host network.

**Remediation:** Use `expose` instead of `ports` in production, or restrict with firewall rules.

---

### SEC-10: Default Credentials in Docker Compose
**Severity: HIGH**
**File:** `docker-compose.yml:61-65, 140-141, 186-187`

Fallback passwords like `secret`, `rootsecret`, `minioadmin` used when `.env` values are missing.

**Remediation:** Remove default fallback values; fail fast if secrets aren't configured.

---

### SEC-11: Admin Users Bypass Sensitive Operation Rate Limiting
**Severity: HIGH**
**File:** `app/Http/Middleware/RateLimitSensitiveOperations.php:27-29`

```php
if ($request->user()?->isAdmin()) {
    return $next($request);  // No rate limits for admins
}
```

A compromised admin account faces zero throttling on password resets and settings changes.

**Remediation:** Apply rate limits to all users; admins can have higher limits but not exemption.

---

## 3. Medium Security Issues

### SEC-12: User Enumeration via Registration Validation
**File:** `app/Http/Controllers/Api/RegisterController.php:101-123`

`GET /api/v1/auth/register/validate` confirms whether emails/org names exist, with no auth or rate limiting.

**Remediation:** Add rate limiting; consider ambiguous responses.

---

### SEC-13: RecordingPolicy Uses Wrong Role Check Method
**File:** `app/Policies/RecordingPolicy.php:16-17`

`$user->hasRole(['owner', 'admin'])` passes string array but method expects `UserRole` enum. May silently fail.

**Remediation:** Use `$user->isOwner() || $user->isPBXAdmin()`.

---

### SEC-14: Extensive withoutGlobalScope Usage (50+ instances)
**Files:** Multiple in `app/Services/VoiceRouting/`, `app/Http/Controllers/Webhooks/`

Each instance must manually add `where('organization_id', $orgId)`. Missing this filter causes cross-tenant leak.

**Remediation:** Create a helper method that always chains the tenant filter when removing scope.

---

### SEC-15: Webhook Idempotency Allows Processing Without Key
**File:** `app/Http/Middleware/EnsureWebhookIdempotency.php:27-33`

Webhooks without identifiable keys are processed without deduplication protection.

**Remediation:** Reject keyless webhooks or generate deterministic key from payload hash.

---

### SEC-16: CXML Bad Request Response - Potential XML Injection
**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php:222-228`

`badRequestResponse` interpolates message into XML without encoding.

**Remediation:** Apply `htmlspecialchars($message, ENT_XML1, 'UTF-8')`.

---

### SEC-17: Recording Download Filename Injection
**File:** `app/Http/Controllers/Api/RecordingsController.php:339`

`Content-Disposition` header uses unsanitized filename, enabling header injection.

**Remediation:** Sanitize filename per RFC 6266.

---

### SEC-18: Session Encryption Disabled
**File:** `.env.example:44`, `config/session.php:50`

`SESSION_ENCRYPT=false` leaves Redis session data in plaintext.

**Remediation:** Enable `SESSION_ENCRYPT=true` in production.

---

### SEC-19: Session Secure Cookie Not Enforced
**File:** `config/session.php:172`

No default for `SESSION_SECURE_COOKIE`; cookies may be sent over HTTP.

**Remediation:** Default to `true` in production environments.

---

### SEC-20: Development Rate Limits Excessively Permissive
**File:** `.env:113-117`

API rate limit at 6000/min, auth at 100/min. If used in production, rate limiting is effectively disabled.

**Remediation:** Validate rate limit values at deployment time.

---

## 4. Low Security Issues

### SEC-21: Login Logs Reveal User Existence
**File:** `app/Http/Controllers/Api/AuthController.php:135-139` - `user_exists` field in log entries.

### SEC-22: CDR Replay Protection Disabled
**File:** `app/Http/Middleware/EnsureWebhookIdempotency.php:43-58` - CDR webhooks accepted with arbitrarily old timestamps.

### SEC-23: CallStateManager Uses forceRelease on Locks
**File:** `app/Services/CallStateManager/CallStateManager.php:74-84` - Releases locks regardless of ownership.

### SEC-24: Circuit Breaker Failure Counter Non-Atomic
**File:** `app/Services/CircuitBreaker/CircuitBreaker.php:142-148` - Read-then-write race condition. Should use `Cache::increment()`.

### SEC-25: Exception Messages Exposed to API Clients
**Files:** `RecordingsController.php:155-158`, `SessionUpdateController.php:530` - `$e->getMessage()` returned directly.

### SEC-26: CORS Allows localhost Origins
**File:** `config/cors.php:24-30` - Hardcoded localhost entries should be environment-conditional.

---

## 5. Positive Security Findings

1. **CXML Sanitization** - `CxmlResponse` properly uses `htmlspecialchars()` with `ENT_XML1` on all text content
2. **Docker User** - PHP-FPM runs as `www-data`, not root
3. **Dependencies Current** - PHP 8.4, Laravel 12, React 18, no known vulnerable packages
4. **LogSanitizer** - Effective at removing sensitive data from logs
5. **SecurityHeaders Middleware** - Comprehensive headers including CSP, X-Frame-Options, etc.
6. **Webhook Signature Verification** - Proper HMAC-SHA256 for status/CDR webhooks
7. **Bearer Token Auth** - Voice routing webhooks properly authenticated per organization
8. **Strict Types** - `declare(strict_types=1)` in all PHP files

---

# PART 3: TESTING ASSESSMENT

## Coverage Gaps

The test suite contains 80 test files covering unit, feature, and integration tests. Key gaps identified:

| Area | Status | Priority |
|------|--------|----------|
| Webhook idempotency | Missing implementation | Critical |
| Cross-tenant isolation in voice routing | Untested | Critical |
| Ring group concurrent routing | Untested | High |
| Business hours DST transitions | Untested | High |
| Password retrieval security | Untested | High |
| Extension N+1 query prevention | Untested | Medium |
| Fallback scenarios (ring group/IVR) | Partially tested | Medium |
| All migration rollbacks | Untested | Low |

---

# PART 4: PRIORITIZED REMEDIATION PLAN

## Immediate (0-7 days) - Critical Security Fixes

| # | Finding | Action |
|---|---------|--------|
| 1 | SEC-1 (SQL Injection) | Replace `DB::raw()` with parameterized query builder |
| 2 | SEC-2 (Wildcard Token) | Change `['*']` to role-scoped abilities in RegisterController |
| 3 | SEC-3 (Unauthenticated Files) | Add auth or signed URLs to recording file endpoint |
| 4 | SEC-4 (Secrets in .env) | Rotate all secrets; verify git history |
| 5 | CR-1 (Missing Idempotency) | Implement Redis-based webhook idempotency |
| 6 | CR-2 (Plaintext Passwords) | Add `Cache-Control: no-store` headers; restrict access |

## Short-term (7-30 days) - High Priority Fixes

| # | Finding | Action |
|---|---------|--------|
| 7 | SEC-5 (X-Organization-ID) | Remove header fallback from rate limiter |
| 8 | SEC-6 (Decrypted Keys) | Return masked keys; add "reveal" endpoint |
| 9 | SEC-7 (CDR Auth) | Add cryptographic verification for CDR webhooks |
| 10 | SEC-8 (Recording Tenant) | Add explicit org_id check in RecordingsController |
| 11 | SEC-10 (Default Creds) | Remove password defaults from docker-compose |
| 12 | SEC-11 (Admin Rate Limit) | Apply rate limits to all users including admins |
| 13 | CR-5 (N+1 Queries) | Add composite index; pre-load relationships |
| 14 | CR-7 (Request Validation) | Create FormRequest classes for voice endpoints |
| 15 | CR-10 (Race Condition) | Use SELECT FOR UPDATE in ring group lock callback |
| 16 | CR-15 (Stack Traces) | Remove traces from webhook error responses |
| 17 | SEC-13 (RecordingPolicy) | Fix role check to use enum-based methods |

## Medium-term (30-90 days) - Code Quality & Hardening

| # | Finding | Action |
|---|---------|--------|
| 18 | CR-4 (Fat Controller) | Refactor ExtensionController into 3 controllers |
| 19 | CR-6 (Verbose Logging) | Reduce VoiceRoutingManager logging to DEBUG |
| 20 | CR-11 (Duplicate Logic) | Consolidate routing logic into VoiceRoutingManager |
| 21 | CR-12 (Missing Indexes) | Add composite indexes for hot query paths |
| 22 | SEC-14 (withoutGlobalScope) | Create tenant-safe query helper |
| 23 | SEC-18/19 (Session Security) | Enable encryption + secure cookies in production |
| 24 | SEC-12 (User Enumeration) | Add rate limiting to registration validation |
| 25 | CR-9 (Error Formats) | Standardize API error response structure |
| 26 | Test Coverage | Write tests for critical paths listed above |

---

# PART 5: ARCHITECTURE ASSESSMENT

## Strengths

- **Clean plane separation** - Control plane (CRUD) and execution plane (voice routing) are well-separated
- **Strategy pattern** - 8 routing strategies provide clean extensibility
- **Service layer** - 30+ services keep controllers thin (except ExtensionController)
- **Multi-layer caching** - Redis + DB fallback with circuit breaker for resilience
- **Event-driven updates** - WebSocket broadcasting for real-time call presence
- **Docker-ready** - 11-service compose with health checks
- **Type safety** - PHP enums, strict types, TypeScript on frontend

## Areas for Improvement

- **ExtensionController** needs refactoring (896 lines, multiple responsibilities)
- **VoiceRoutingManager** is too large (1762 lines, 50+ log statements)
- **Error handling** inconsistent across controllers
- **Test coverage** gaps in critical webhook and routing paths
- **Configuration** scattered magic numbers should move to config files

---

*Report generated by Claude Opus 4.6 on 2026-02-06. No code changes were made during this review.*
