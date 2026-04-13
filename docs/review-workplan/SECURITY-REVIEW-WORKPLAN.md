# Security & Privacy Review Workplan for OpBX

**Version**: 1.0
**Last Updated**: 2026-04-09
**Applies To**: OpBX PBX Platform (Laravel 12 + React 18 + Go Dialer Worker)
**Classification**: Internal — Security Sensitive

---

## Purpose

This document is a **repeatable workplan** for conducting comprehensive security and privacy reviews on the OpBX codebase. When a review is conducted, the auditor follows this workplan and produces a **to-do document** saved at:

```
/docs/review-workplan/security-review-{YYYY-MM-DD}/
```

The to-do document lists all required tasks for resolving any identified security or privacy issue, classified by severity and mapped to OWASP/CWE standards where applicable.

---

## Threat Model Overview

OpBX is a multi-tenant business PBX platform with a significant attack surface:

### Trust Boundaries
```
┌───────────────────────────────────────────────────────────┐
│  Internet (Untrusted)                                     │
│  ┌─────────┐  ┌───────────┐  ┌───────────────────────┐   │
│  │ Browser  │  │ Cloudonix │  │ External Webhooks     │   │
│  │ (SPA)    │  │ CPaaS     │  │ (Call Notifications)  │   │
│  └────┬─────┘  └────┬──────┘  └───────────────────────┘   │
│       │              │                                     │
├───────┼──────────────┼─────────────────────────────────────┤
│  DMZ  │   Nginx :80  │                                     │
├───────┼──────────────┼─────────────────────────────────────┤
│       ▼              ▼                                     │
│  ┌─────────┐  ┌───────────┐  ┌─────────┐  ┌───────────┐  │
│  │ Laravel  │  │ Webhook   │  │ Queue   │  │ Scheduler │  │
│  │ API      │  │ Handlers  │  │ Worker  │  │           │  │
│  └────┬─────┘  └────┬──────┘  └────┬────┘  └───────────┘  │
│       │              │              │                       │
│  ┌────┴──────────────┴──────────────┴────┐                 │
│  │  Internal Network (Docker bridge)     │                 │
│  │  MySQL │ Redis │ MinIO │ Soketi │ Go  │                 │
│  └───────────────────────────────────────┘                 │
└───────────────────────────────────────────────────────────┘
```

### Key Assets
| Asset | Sensitivity | Storage |
|-------|------------|---------|
| User credentials (passwords) | Critical | MySQL (bcrypt hashed) |
| Cloudonix API keys | Critical | MySQL (encrypted at rest) |
| SIP extension passwords | High | MySQL (plain text — SIP protocol requirement) |
| Call Detail Records | High | MySQL (raw_cdr JSON) |
| Recording audio files | High | MinIO (S3-compatible) |
| PII (names, emails, phone numbers) | High | MySQL |
| Session tokens (Sanctum) | High | MySQL + localStorage |
| Distribution lists (phone numbers) | Medium | MySQL |
| Webhook delivery URLs | Medium | MySQL |
| Organization configuration | Medium | MySQL + Redis (cached) |

### Threat Actors
1. **External attacker** — Targets exposed endpoints (API, webhooks, health checks)
2. **Malicious tenant** — Attempts cross-tenant data access or privilege escalation
3. **Compromised Cloudonix account** — Sends spoofed webhooks to manipulate call routing
4. **Insider (malicious user)** — Lower-role user attempts vertical privilege escalation
5. **Supply chain** — Compromised dependency introduces backdoor

---

## Pre-Review Preparation

### 1. Tool Setup

```bash
# PHP dependency vulnerability check
composer audit

# NPM dependency vulnerability check
cd frontend && npm audit

# Go dependency vulnerability check
cd dialer-worker && go list -m all | nancy sleuth
# (or) govulncheck ./...

# Static analysis (if available)
vendor/bin/phpstan analyse --level=max
cd frontend && npm run type-check

# Check for secrets in codebase
git log --all --diff-filter=A -- '*.env' '*.key' '*.pem' '*credentials*' '*secret*'
git grep -i "password\|secret\|api_key\|token" -- '*.php' '*.ts' '*.go' ':!vendor' ':!node_modules'
```

### 2. Scope Definition

For each review, determine scope:
- **Full review**: All 11 areas (recommended quarterly)
- **Focused review**: Specific areas triggered by code changes
- **Incident-driven**: Targeted areas after a security event
- **Pre-release**: Areas 1-6, 8 (before any deployment)

### 3. Prior Review Cross-Reference

Before starting, review the most recent security review to-do document and check:
- Which previously-identified issues have been resolved
- Which issues remain open
- Whether any previously-fixed issues have regressed

---

## Review Execution Order

Security reviews MUST be conducted in this priority order:

### Phase 1: Authentication & Tenant Isolation (Critical Path)
- Area 1: Authentication & Session Security
- Area 2: Authorization & Access Control

### Phase 2: Input & API Attack Surface
- Area 3: Input Validation & Injection
- Area 4: Webhook & API Security

### Phase 3: Data Protection
- Area 5: Data Protection & Privacy
- Area 6: Cryptography

### Phase 4: Infrastructure & Supply Chain
- Area 7: Infrastructure & Container Security
- Area 8: Dependency & Supply Chain Security

### Phase 5: Business Logic & Real-Time
- Area 9: Business Logic Security
- Area 10: Real-Time & WebSocket Security

### Phase 6: Compliance
- Area 11: Compliance & Best Practices

---

## Review Area 1: Authentication & Session Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Login flow | `app/Http/Controllers/Api/AuthController.php` (506 lines) | Credential handling, mode detection, token creation |
| Registration | `app/Http/Controllers/Api/RegisterController.php` | Org creation, initial token |
| Sanctum config | `config/sanctum.php`, `config/auth.php` | Token expiry, guard config |
| Frontend auth | `frontend/src/context/AuthContext.tsx`, `frontend/src/services/auth.service.ts`, `frontend/src/services/api.ts` | Token storage, interceptors |
| Password endpoints | `app/Http/Controllers/Api/ProfileController.php`, `app/Http/Controllers/Api/UsersController.php` | Password change, token revocation |
| Rate limiting | `app/Http/Middleware/RateLimitSensitiveOperations.php` | Brute force protection |
| reCAPTCHA | `app/Rules/Recaptcha.php` | Registration protection |

### What to Look For

#### 1.1 Token Lifecycle
- [ ] Tokens are created with **role-scoped abilities** (not wildcard `['*']`)
- [ ] Tokens expire after configured duration (default 24h)
- [ ] Old tokens are revoked on new login (`AuthController::login`)
- [ ] All tokens are revoked on password change
- [ ] Token is not exposed in URL query parameters
- [ ] Refresh endpoint properly validates and rotates tokens

#### 1.2 Password Security
- [ ] Passwords hashed with bcrypt (cost factor ≥ 10)
- [ ] Password complexity enforced server-side (not just frontend Zod schema)
- [ ] Current password required for password change
- [ ] Password not logged or included in audit entries
- [ ] `password_confirmation` field required and validated

#### 1.3 Session & Cookie Security
- [ ] Cookie auth uses `HttpOnly`, `Secure`, `SameSite=Lax` (or `Strict`)
- [ ] CSRF protection enabled for cookie-based auth
- [ ] Session fixation prevented (session regenerated on login)
- [ ] `shouldUseCookieAuth()` mode detection cannot be spoofed to bypass security

#### 1.4 Frontend Token Storage
- [ ] Token stored in `localStorage` — document the risk vs alternatives (httpOnly cookie)
- [ ] Token cleared on logout and on 401 response
- [ ] No token leakage to third-party scripts (CSP enforced)
- [ ] Token not included in error reporting or analytics payloads

#### 1.5 Brute Force Protection
- [ ] Login: rate limited (currently 5/min per IP via `throttle:auth`)
- [ ] Registration: rate limited with configurable limits
- [ ] Password change: rate limited via `sensitive-operations` middleware
- [ ] Account lockout after N failed attempts (check if implemented)
- [ ] Rate limit bypass via IP rotation considered (API key or user-based limits)

#### 1.6 Auth Mode Detection Security
- [ ] `shouldUseCookieAuth()` (AuthController line ~263) — verify that an attacker cannot force cookie mode to exploit CSRF or force token mode to bypass session controls
- [ ] `X-Auth-Mode` header cannot be injected by a middlebox

### Testing Methodology
- **Manual**: Trace login/registration/password-change flows end-to-end
- **Automated**: `tests/Feature/Auth/` test coverage for all auth paths
- **Penetration**: Attempt login without rate limit, token reuse after revocation, cross-tenant token use

---

## Review Area 2: Authorization & Access Control

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Organization scope | `app/Scopes/OrganizationScope.php` (92 lines) | Query-level tenant isolation |
| Tenant middleware | `app/Http/Middleware/EnsureTenantScope.php` (78 lines) | Request-level tenant validation |
| All policies | `app/Policies/*.php` (17 files) | RBAC enforcement per resource |
| Platform management | `app/Http/Middleware/EnsurePlatformManager.php`, Platform controllers | Cross-tenant access |
| Scope bypass usage | All files using `OrganizationScope::bypass()` | Privilege escalation risk |

### What to Look For

#### 2.1 Tenant Isolation Completeness
- [ ] **Every model** with `organization_id` has `#[ScopedBy([OrganizationScope::class])]` attribute
- [ ] Known exceptions are intentional and documented:
  - Models using `boot()` instead of attribute: `Recording`, `SessionUpdate`
  - Models without scope: `Organization`, `PlatformAuditLog`, `CallDetailRecord` (uses explicit scope)
  - Child models without org_id: `RingGroupMember`, `IvrMenuOption`, `BusinessHoursScheduleDay`, etc.
- [ ] No raw SQL queries bypass the organization scope
- [ ] No `withoutGlobalScope(OrganizationScope::class)` without compensating org_id check
- [ ] Security failsafe active: unauthenticated → `WHERE 1 = 0`

#### 2.2 OrganizationScope Bypass Audit
Enumerate every usage of `OrganizationScope::bypass()` and verify each is necessary and safe:

| Expected Bypass Location | Justification |
|--------------------------|---------------|
| AuthController (login) | User lookup by email across all orgs |
| Platform controllers | Cross-tenant admin operations |
| Webhook controllers | No authenticated user context |
| Dialer worker API | Service-to-service auth |

For each bypass found:
- [ ] The bypass is time-bounded (closure returns, bypass ends)
- [ ] The bypassed query includes explicit org_id filtering where data isolation matters
- [ ] No user-supplied input directly influences the bypassed query without validation

#### 2.3 Policy Coverage
- [ ] Every `apiResource` route has a corresponding Policy class
- [ ] Every custom action route (toggle-status, duplicate, etc.) has policy check
- [ ] Policies enforce role hierarchy correctly (Owner > PBX Admin > PBX User > Reporter)
- [ ] Self-referential protections: cannot delete self, cannot change own role (except via profile)
- [ ] Last-owner protection: cannot delete or demote the last owner of an organization

#### 2.4 Platform Manager Escalation
- [ ] Platform manager routes are ALL gated by `platform.manager` middleware
- [ ] `is_platform_manager` flag cannot be set via regular user API
- [ ] Platform manager cannot escalate another user to platform manager without being one
- [ ] Revoking platform manager status forces re-authentication (all tokens revoked)
- [ ] Platform audit log covers all cross-tenant mutations

#### 2.5 Horizontal Privilege Escalation
- [ ] Users cannot access resources in their own org belonging to another user (where restricted)
- [ ] `ExtensionPolicy` "own only" check for PBX_USER is correctly implemented
- [ ] `UserPolicy` "self only" view/update for PBX_USER correctly implemented
- [ ] Password reset endpoints verify the requesting user has authority over the target user

### Testing Methodology
- **Automated**: `tests/Unit/Policies/` for policy logic, `tests/Feature/Security/` for integration
- **Manual**: Use Postman/curl with tokens of different roles to hit all endpoints
- **Penetration**: Attempt to access org B resources with org A token; attempt PBX_USER performing admin actions

---

## Review Area 3: Input Validation & Injection

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Form requests | `app/Http/Requests/**/*.php` (52 files) | Validation rules |
| CXML builder | `app/Services/CxmlBuilder/CxmlBuilder.php` (714 lines) | XML injection |
| Phone number service | `app/Services/PhoneNumberService.php` | E.164 validation bypass |
| IVR state | `app/Services/IvrStateService.php` | Redis key injection |
| Recording upload | `app/Services/Recording/RecordingUploadService.php` (381 lines) | File upload security |
| Distribution lists | `app/Services/AutoDialer/ListValidationService.php` (314 lines) | CSV injection |
| WebSocket URL builder | `app/Services/AiAssistant/WebSocketUrlBuilder.php` | URL injection |
| Business hours parser | `app/Models/BusinessHoursSchedule.php` (parseTargetId) | Target ID injection |
| Raw queries | `app/Http/Controllers/Api/SessionUpdateController.php` | SQL injection |

### What to Look For

#### 3.1 SQL Injection
- [ ] Audit all `DB::raw()`, `whereRaw()`, `selectRaw()`, `orderByRaw()` calls
- [ ] Verify parameterized queries are used everywhere (no string concatenation in queries)
- [ ] JSON column queries (`routing_config->extension_id`) are properly escaped
- [ ] `SessionUpdateController` complex raw query (line ~33) uses bindings correctly
- [ ] Platform dashboard aggregate queries use bindings
- [ ] CDR export query uses bindings for date filters

#### 3.2 CXML Injection (Cross-Site Scripting via XML)
- [ ] `CxmlBuilder.php` uses `DOMDocument` (which auto-encodes XML entities) — verify no bypass
- [ ] All user/attacker-controlled data entering CXML is XML-encoded:
  - Phone numbers from webhook payloads
  - Extension numbers from database
  - Conference room names/IDs
  - IVR prompt text (TTS)
  - AI assistant service URLs
  - WebSocket URLs
- [ ] No use of `innerHTML` or raw string concatenation for XML generation
- [ ] `CxmlResponse.php` (legacy) also properly encodes

#### 3.3 Path Traversal
- [ ] Recording upload filename sanitization blocks `../`, `..\\`, null bytes
- [ ] MinIO storage path construction does not allow tenant A to access tenant B's files
- [ ] `serveMinioFile` route pattern `[0-9]+/.+` — verify the `+` cannot include `..`
- [ ] Recording download token cannot be manipulated to access different files
- [ ] CSV upload temporary file path is sanitized

#### 3.4 Server-Side Request Forgery (SSRF)
- [ ] **Call notification webhook URLs** — verify URL validation prevents internal network access
  - Blocked: `127.0.0.1`, `10.x.x.x`, `172.16-31.x.x`, `192.168.x.x`, `169.254.x.x`, `localhost`, `0.0.0.0`
  - Blocked: `file://`, `ftp://`, `gopher://` schemes
- [ ] **Recording remote URLs** — `RecordingRemoteService` validates URLs
- [ ] **AI assistant service URLs** — verify no internal network access
- [ ] **WebSocket URL builder** — verify `wss://` scheme enforcement
- [ ] **Holiday API** (`date.nager.at`) — external fetch controlled by server
- [ ] **Email provider URLs** — driver configuration validated
- [ ] **Cloudonix API base URL** — from config, not user-controlled

#### 3.5 CSV Injection
- [ ] Distribution list CSV upload — do cells starting with `=`, `+`, `-`, `@`, `\t`, `\r` get sanitized?
- [ ] CSV export (CDR export, distribution list download) — output sanitization for formula injection
- [ ] Check both upload parsing and download generation paths

#### 3.6 E.164 Phone Number Validation
- [ ] Regex `/^\+[1-9]\d{1,14}$/` is enforced server-side (not just frontend)
- [ ] Non-E.164 mode validation (`digits + #`) is equally strict
- [ ] Phone numbers from webhook payloads are validated before database storage
- [ ] Phone number normalization (E.164 conversion) handles edge cases

#### 3.7 JSON Payload Manipulation
- [ ] `routing_config` JSON column — can a user inject arbitrary keys?
- [ ] `configuration` JSON column on extensions — validated against expected schema per type?
- [ ] `service_params` JSON on extensions — arbitrary data risk assessment
- [ ] `schedule` JSON on campaigns — validated structure
- [ ] `raw_cdr` JSON — stored as-is from webhook (read-only, but check for XSS on display)

#### 3.8 Redis Key Injection
- [ ] IVR state keys: `ivr:call:{callSid}` — is `callSid` validated?
- [ ] Lock keys: `lock:call:{callId}`, `lock:ring_group:{id}` — is ID validated?
- [ ] Idempotency keys: `idem:webhook:{key}` — is key properly hashed?
- [ ] Dialer keys: `dialer:cac:{id}:active` — is campaign ID validated?

### Testing Methodology
- **Static analysis**: Search for raw query patterns, string concatenation in SQL/XML/HTML
- **Manual**: Inject payloads in all input fields (phone numbers, names, URLs, CSV cells)
- **Automated**: Use OWASP ZAP or Burp Suite against API endpoints
- **Fuzzing**: Supply malformed phone numbers, oversized JSON, nested objects

---

## Review Area 4: Webhook & API Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Signature verification | `app/Http/Middleware/VerifyCloudonixSignature.php` (230 lines) | Auth completeness |
| Voice webhook auth | `app/Http/Middleware/VerifyVoiceWebhookAuth.php` (223 lines) | Token verification |
| Idempotency | `app/Http/Middleware/EnsureWebhookIdempotency.php` (244 lines) | Replay protection |
| Rate limiting | `app/Http/Middleware/RateLimitPerOrganization.php` | Per-org limits |
| Webhook routes | `routes/webhooks.php` | Middleware coverage |
| Dialer worker auth | `app/Http/Middleware/DialerWorkerAuth.php` | Service auth |
| All API routes | `routes/api.php`, `routes/platform.php` | Auth middleware |

### What to Look For

#### 4.1 Webhook Signature Verification
- [ ] **All webhook routes** have `webhook.signature` middleware applied — no gaps
- [ ] `VerifyCloudonixSignature` uses `hash_equals()` for timing-safe comparison
- [ ] CDR webhook org identification via `domain_uuid` cannot be spoofed (no user-controlled UUID injection)
- [ ] Bearer token extraction is robust (handles malformed Authorization headers)
- [ ] CXML error responses from `VerifyVoiceWebhookAuth` do not leak internal information

#### 4.2 Replay Attack Protection
- [ ] `EnsureWebhookIdempotency` timestamp validation: rejects >5min old (except CDR), >1min future
- [ ] Idempotency key TTL (24h) is sufficient but not excessive
- [ ] Redis key `idem:webhook:{key}` uses SHA-256 hashing to prevent key manipulation
- [ ] Cached responses do not contain stale routing data that could cause misrouting
- [ ] Session-update endpoint (high-velocity) intentionally skips idempotency — verify this is safe

#### 4.3 Rate Limiting Coverage
Audit every endpoint for rate limiting:

| Route Group | Rate Limit | Verify |
|-------------|-----------|--------|
| `v1/auth/login` | `throttle:auth` (5/min/IP) | ✓ Applied |
| `v1/auth/register` | `throttle:registration` | ✓ Applied |
| Protected API routes | `rate_limit_org:api` (60/min) | Verify ALL included |
| Voice routing | `voice.webhook.auth` (no explicit rate limit) | **Check if needed** |
| Webhook endpoints | `rate_limit_org:webhook` missing on some | **Check per-route** |
| Session updates | No rate limiting (intentional) | Document risk |
| Auto-dialer routes | No rate limiting (intentional) | Document risk |
| Platform routes | No explicit rate limiting | **Check if needed** |
| Dialer worker API | `throttle:dialer-worker` | ✓ Applied |
| Health endpoints | No rate limiting | Check for abuse |

#### 4.4 CXML Response Security
- [ ] CXML responses cannot be manipulated by attacker-controlled webhook payload data
- [ ] Error CXML responses (`<Say>Unauthorized</Say><Hangup/>`) do not leak information
- [ ] CXML generation timeouts — if voice routing takes too long, what happens?
- [ ] `actionUrl` callbacks in CXML point to authenticated endpoints

#### 4.5 API Route Authentication Gaps
- [ ] Every route in `routes/api.php` (except explicitly public ones) has `auth:sanctum`
- [ ] Public routes are intentionally public:
  - `/api/health` — no sensitive data
  - `/api/sanctum/csrf-cookie` — CSRF setup
  - `/api/v1/auth/login`, `/register` — authentication endpoints
  - `/api/v1/validate-email` — rate limited
  - `/api/v1/recordings/download` — self-authenticating token
  - `/api/storage/recordings/{path}` — HMAC-signed URL
- [ ] No route accidentally lacks `tenant.scope` middleware (except platform routes)

#### 4.6 Information Disclosure in Errors
- [ ] API error responses do not include stack traces in production
- [ ] Webhook error responses do not expose internal file paths
- [ ] 404 responses do not reveal whether a resource exists (vs. no access)
- [ ] Health endpoints do not expose version numbers, internal IPs, or service details to unauthenticated users

### Testing Methodology
- **Manual**: Review `routes/webhooks.php` and `routes/api.php` line by line for middleware gaps
- **Penetration**: Send webhooks without signature, with expired timestamps, with replayed payloads
- **Automated**: Script to hit every API route without auth and verify 401/403

---

## Review Area 5: Data Protection & Privacy

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Encrypted fields | `app/Models/CloudonixSettings.php` | Encryption at rest |
| SIP passwords | `app/Models/Extension.php` | Plain text storage |
| API key masking | `app/Http/Controllers/Api/SettingsController.php` | Response masking |
| Log sanitization | `app/Services/Logging/LogSanitizer.php` (224 lines) | PII in logs |
| Audit logging | `app/Services/Logging/AuditLogger.php` (416 lines) | Sensitive data in audit |
| CDR storage | `app/Models/CallDetailRecord.php` | Call record sensitivity |
| Recording access | `app/Services/Recording/RecordingAccessService.php` | Access control |
| Data export | `CallDetailRecordController::export`, `DistributionListController::download` | Export security |
| Email credentials | `app/Models/CallNotificationsSettings.php` | Auth credential storage |

### What to Look For

#### 5.1 Encryption at Rest
- [ ] `CloudonixSettings.domain_api_key` — encrypted via Laravel `$casts` (verify `encrypted` cast)
- [ ] `CloudonixSettings.domain_requests_api_key` — encrypted
- [ ] `CallNotificationsSettings.auth_credentials` — verify encryption or at minimum JSON with encrypted fields
- [ ] Laravel `APP_KEY` is sufficiently strong (32 bytes, AES-256-CBC)
- [ ] Key rotation procedure documented

#### 5.2 SIP Password Handling
- [ ] SIP passwords stored in plain text (Extension model) — **document as accepted risk**
- [ ] SIP passwords NOT returned in standard extension list API responses
- [ ] `ExtensionPasswordController::getPassword` requires `sensitive-operations` middleware
- [ ] Response includes `Cache-Control: no-store, no-cache, must-revalidate` headers
- [ ] SIP passwords are NOT included in audit log entries
- [ ] SIP passwords are NOT included in Cloudonix sync payloads sent over unencrypted channels

#### 5.3 API Key Exposure Prevention
- [ ] `SettingsController::getCloudonixSettings` returns masked keys (e.g., `****XXXX`)
- [ ] No endpoint returns unmasked API keys
- [ ] API keys are NOT logged (verify LogSanitizer patterns)
- [ ] API keys in request headers are sanitized in audit logs

#### 5.4 PII in Logs (LogSanitizer Coverage)
- [ ] All 32 sensitive key patterns are correctly matched (password, token, secret, api_key, etc.)
- [ ] Bearer tokens in Authorization headers are masked
- [ ] Phone numbers in webhook logs — assess if these should be masked
- [ ] Email addresses in audit logs — assess if hashing is needed
- [ ] User agent strings — ensure they don't contain embedded PII
- [ ] `hashDomain()` correctly SHA-256 hashes email domains
- [ ] No log statement anywhere bypasses the sanitizer for sensitive data

#### 5.5 Call Record Data Sensitivity
- [ ] CDR `raw_cdr` JSON — contains full Cloudonix payload, may include sensitive metadata
- [ ] CDR export (CSV) — assess what fields are exported and who can access
- [ ] Call logs — `from_number` and `to_number` are PII (phone numbers)
- [ ] Session updates — `caller_id` and `destination` are PII
- [ ] Blocked call logs — store caller_id and webhook_payload

#### 5.6 Recording Security
- [ ] Encrypted token access uses AES-256-CBC with proper IV
- [ ] Token expiry (30min default) is appropriate
- [ ] HMAC-signed URLs for Cloudonix access use APP_KEY (verify strength)
- [ ] Signed URL expiry (5min cache) is appropriate
- [ ] Secure deletion overwrites file content before unlink (if enabled)
- [ ] MinIO bucket is not publicly accessible
- [ ] Recording path includes org_id to prevent cross-tenant access

#### 5.7 Data Export Security
- [ ] CDR CSV export is role-restricted (who can export?)
- [ ] Distribution list CSV download is role-restricted
- [ ] Export endpoints enforce tenant scope
- [ ] Export streams are chunked (preventing memory exhaustion DoS)
- [ ] Exported CSV files do not include encrypted/sensitive fields

#### 5.8 Right to Deletion
- [ ] Organization deletion cascade (`PlatformOrganizationController`) covers all related data
- [ ] Soft deletes: verify `deleted_at` records are excluded from all queries
- [ ] User deletion properly cleans up tokens, extension associations
- [ ] CDR data retention — is there a retention/purge policy?
- [ ] Recording file deletion — are files actually removed from MinIO (not just DB reference)?

### Testing Methodology
- **Manual**: Search for `Log::`, `logger->`, `info(`, `error(` calls and verify sanitization
- **Automated**: Run `git grep -n "password\|api_key\|secret\|token" app/` excluding LogSanitizer/test files
- **Data mapping**: Create complete PII data flow diagram from ingestion to storage to display

---

## Review Area 6: Cryptography

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Laravel encryption | `config/app.php` (APP_KEY) | Key management |
| Password hashing | `config/hashing.php`, User model | Bcrypt configuration |
| Token generation | `app/Services/PasswordGenerator.php` | Randomness quality |
| HMAC signatures | `RecordingsController`, `VerifyCloudonixSignature` | Signature security |
| Recording tokens | `RecordingAccessService.php` | AES-256-CBC usage |
| Webhook signatures | `EnsureWebhookIdempotency.php` | SHA-256 hashing |

### What to Look For

#### 6.1 Key Management
- [ ] `APP_KEY` is generated with `php artisan key:generate` (not a weak/default value)
- [ ] Entrypoint `validate-env.sh` checks for weak/default keys
- [ ] Key is not committed to git (`.env` in `.gitignore`)
- [ ] Key rotation procedure: what breaks if APP_KEY changes? (encrypted fields, signed URLs, tokens)

#### 6.2 Password Hashing
- [ ] Bcrypt used with cost factor ≥ 10 (Laravel default is 12)
- [ ] `Hash::check()` used for verification (not direct comparison)
- [ ] No custom hashing implementations

#### 6.3 Random Number Generation
- [ ] `PasswordGenerator` uses `random_bytes()` or `random_int()` (CSPRNG)
- [ ] Sanctum token generation uses secure randomness
- [ ] Correlation IDs (AuditLogger) use `Str::uuid()` (cryptographically random)
- [ ] No use of `rand()`, `mt_rand()`, or `uniqid()` for security-sensitive operations

#### 6.4 HMAC & Signature Security
- [ ] Recording HMAC: `HMAC-SHA256("{path}|{expires}", APP_KEY)` — verify separator prevents ambiguity
- [ ] Webhook signature: `hash_equals()` used (timing-safe)
- [ ] All signature comparisons are constant-time

#### 6.5 TLS
- [ ] HSTS headers in production (`config/security.php`)
- [ ] `upgrade-insecure-requests` CSP directive in production
- [ ] All external HTTP calls use HTTPS (Cloudonix API, reCAPTCHA, email providers, holiday API)
- [ ] Verify no `verify: false` or TLS verification disabling in HTTP clients

### Testing Methodology
- **Static analysis**: Search for weak cryptographic functions (`md5`, `sha1` for security, `rand()`)
- **Configuration review**: Check hashing config, encryption config, TLS settings
- **Key audit**: Verify key strength and rotation procedures

---

## Review Area 7: Infrastructure & Container Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Docker Compose | `docker-compose.yml` | Service exposure, secrets |
| PHP Dockerfile | `docker/php/Dockerfile` | Base image, user, permissions |
| Entrypoint | `docker/php/entrypoint.sh` | Startup security validation |
| Nginx config | `docker/nginx/conf.d/default.conf` | Reverse proxy security |
| MySQL config | `docker/mysql/my.cnf` | Database security |
| Environment | `.env.example` | Secret management |
| Env validation | `docker/scripts/validate-env.sh` | Weak password detection |

### What to Look For

#### 7.1 Docker Image Security
- [ ] Base image `php:8.4-fpm-alpine` — check for known vulnerabilities
- [ ] Runs as non-root user (`www-data`)
- [ ] No unnecessary packages installed
- [ ] Multi-stage build eliminates build dependencies from runtime image
- [ ] `composer install --no-dev` in production
- [ ] Image size is reasonable (no bloat)

#### 7.2 Secret Management
- [ ] `.env` file is in `.gitignore`
- [ ] No secrets hardcoded in `docker-compose.yml`
- [ ] Secrets passed via environment variables (not build args)
- [ ] `validate-env.sh` checks for weak defaults on:
  - `DB_PASSWORD`, `DB_ROOT_PASSWORD`
  - `REDIS_PASSWORD`
  - MinIO access/secret keys
  - `APP_KEY`
- [ ] No `.env.example` contains real credentials
- [ ] `NGROK_AUTHTOKEN` documented as optional (not committed)

#### 7.3 Network Exposure
- [ ] MySQL port NOT exposed to host (only internal Docker network)
- [ ] Redis port NOT exposed to host
- [ ] MinIO port NOT exposed to host
- [ ] Only Nginx port 80 exposed (and ngrok 4040 for dev)
- [ ] Soketi port 6001 only internal (proxied via Nginx at `/app/`)
- [ ] Go dialer worker port 8081 only internal

#### 7.4 Nginx Security
- [ ] Hidden files (`.env`, `.git`, etc.) blocked with deny rules
- [ ] `composer.json`, `composer.lock` not accessible
- [ ] PHP-FPM access restricted to Nginx only
- [ ] Client body size limited (`client_max_body_size`)
- [ ] Request timeouts configured appropriately
- [ ] No `autoindex on` directives
- [ ] Server version hidden (`server_tokens off`)
- [ ] WebSocket upgrade path (`/app/`) properly secured

#### 7.5 Database Security
- [ ] MySQL binds to internal Docker network only
- [ ] Strong passwords enforced (validated in entrypoint)
- [ ] `mysql_native_password` or `caching_sha2_password` used
- [ ] No default/test databases accessible

#### 7.6 Redis Security
- [ ] Password authentication enabled (`requirepass`)
- [ ] Protected mode enabled
- [ ] No dangerous commands exposed (`FLUSHALL`, `CONFIG SET`)
- [ ] `dialer` Redis connection prefix-free — verify this doesn't leak data between contexts

#### 7.7 ngrok Security
- [ ] ngrok container only starts in development mode
- [ ] No ngrok tunnel configuration committed to git
- [ ] ngrok web interface (port 4040) only accessible locally
- [ ] Clear documentation about ngrok risks in production

### Testing Methodology
- **Configuration review**: Read all Docker/Nginx/MySQL/Redis configs line by line
- **Port scan**: `docker compose ps` to verify exposed ports
- **Vulnerability scan**: `docker scout cves` or Trivy on built images
- **Network test**: From within a container, try accessing other containers' internal ports

---

## Review Area 8: Dependency & Supply Chain Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| PHP dependencies | `composer.json`, `composer.lock` | Known vulnerabilities |
| NPM dependencies | `frontend/package.json`, `package-lock.json` | Known vulnerabilities |
| Go dependencies | `dialer-worker/go.mod`, `dialer-worker/go.sum` | Known vulnerabilities |
| Docker base images | All Dockerfiles | Image currency |
| Third-party services | Various configs | Trust boundaries |

### What to Look For

#### 8.1 PHP Dependencies
- [ ] Run `composer audit` — zero critical/high vulnerabilities
- [ ] Key framework versions current (Laravel 12, Sanctum, etc.)
- [ ] No abandoned packages (`composer outdated --direct`)
- [ ] `composer.lock` committed and matches `composer.json`

#### 8.2 NPM Dependencies
- [ ] Run `npm audit` — zero critical/high vulnerabilities
- [ ] React, Vite, TanStack Query at current stable versions
- [ ] No `eval()` or dynamic `require()` in dependencies
- [ ] `package-lock.json` committed

#### 8.3 Go Dependencies
- [ ] Run `govulncheck ./...` — zero vulnerabilities
- [ ] `go.sum` committed for integrity verification
- [ ] Key dependencies (gin, go-redis) at current versions

#### 8.4 Docker Base Images
- [ ] `php:8.4-fpm-alpine` — latest patch version
- [ ] `mysql:8.0` — latest patch version
- [ ] `redis:7-alpine` — latest patch version
- [ ] `nginx:alpine` — latest patch version
- [ ] `node:20-alpine` — latest LTS patch version
- [ ] `minio/minio:latest` — consider pinning to specific version

#### 8.5 Third-Party Service Trust
- [ ] Cloudonix API — TLS enforced, API key scoped appropriately
- [ ] Google reCAPTCHA — verify fallback when service is down
- [ ] UserCheck.com — email validation, PII sent externally (email addresses)
- [ ] date.nager.at — holiday API, no PII sent
- [ ] Email providers (Mailgun, Mailjet, MailerLite, Brevo) — credentials stored securely

### Testing Methodology
- **Automated**: `composer audit`, `npm audit`, `govulncheck`, `docker scout`
- **Manual**: Review each direct dependency for maintenance status
- **Policy**: Establish maximum vulnerability age before mandatory update

---

## Review Area 9: Business Logic Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Voice routing | `VoiceRoutingManager.php` (2,136 lines) | Routing manipulation |
| Call state machine | `CallStateManager.php` (310 lines) | State transition safety |
| Ring group locks | `RingGroupController.php` | Concurrency |
| ALBS distribution | `AlbsDistributionService.php` (372 lines) | Algorithm manipulation |
| Campaign lifecycle | `CampaignLifecycleManager.php` (197 lines) | State transitions |
| Owner deletion | `UsersController.php`, `PlatformUserController.php` | Last-owner race |
| CAC rate limiting | `dialer-worker/internal/limiter/cac.go` | Counter manipulation |
| Redis reconciliation | `dialer-worker/internal/redis/client.go` | Self-healing integrity |

### What to Look For

#### 9.1 Race Conditions
- [ ] **Webhook processing**: concurrent webhooks for same call_id handled safely
  - Redis lock `lock:call:{call_id}` acquired before state transitions
  - Idempotency key prevents duplicate processing
- [ ] **Owner deletion**: TOCTOU race between count check and delete
  - Uses database transaction with pessimistic locking (`lockForUpdate`)
- [ ] **Ring group update**: Redis lock `lock:ring_group:{id}` with 10s timeout
  - Returns 409 on conflict
- [ ] **ALBS update**: Redis lock `lock:albs:{id}` with 10s timeout
- [ ] **Extension Cloudonix sync**: concurrent sync operations for same extension
- [ ] **Campaign start/pause**: concurrent lifecycle transitions

#### 9.2 Call Routing Manipulation
- [ ] Can an attacker-controlled webhook payload influence which extension a call routes to?
- [ ] Business hours time-based routing: timezone manipulation?
- [ ] DID routing config changes take effect immediately (no stale cache serving old routes)
- [ ] Blacklist bypass: can a blocked number bypass the blacklist via a different DID?
- [ ] Whitelist bypass: can an unauthorized outbound call be placed?

#### 9.3 Distributed Lock Safety
- [ ] All Redis locks have TTL (no indefinite locks on crash)
- [ ] Lock timeout values are appropriate (not too long, not too short)
- [ ] `DatabaseLockService` fallback works correctly when Redis is down
- [ ] Lock key collisions are impossible (unique per resource type + ID)
- [ ] `ResilientCacheService` health check (60s re-check) is appropriate

#### 9.4 Campaign Rate Limiting Abuse
- [ ] CAC counter cannot be manually decremented to allow more concurrent calls
- [ ] CPS rate limiting is enforced server-side (not just client/worker-side)
- [ ] Redis CAC counter reconciliation (`ReconcileActiveCalls`) prevents counter drift
- [ ] Campaign cannot be started with CAC > 50 or CPS > 5
- [ ] Pausing a campaign properly resets in-flight state

#### 9.5 Resource Exhaustion
- [ ] Distribution list CSV: 100,000 entry limit enforced
- [ ] API pagination: all list endpoints paginated (no unbounded queries)
- [ ] CDR export: streaming with chunking (1000/batch)
- [ ] File upload: 5MB size limit enforced
- [ ] Queue jobs have timeout (90s) and retry limits (3)
- [ ] WebSocket connections limited (Soketi max 10000)

### Testing Methodology
- **Manual**: Trace critical paths for race windows
- **Concurrent testing**: Use `ab` or `wrk` to send concurrent requests to same endpoint
- **State machine**: Enumerate all valid and invalid state transitions, test each

---

## Review Area 10: Real-Time & WebSocket Security

### What to Audit

| Component | Files | Concern |
|-----------|-------|---------|
| Channel authorization | `routes/channels.php` | Access control |
| Echo service | `frontend/src/services/echo.service.ts` | Client config |
| Soketi config | `docker-compose.yml` (soketi service) | Server security |
| Broadcast events | `app/Events/Call*.php` | Data exposure |
| Broadcasting config | `config/broadcasting.php` | Credentials |

### What to Look For

#### 10.1 Channel Authorization
- [ ] Presence channel `org.{organizationId}` — user must belong to organization
- [ ] Private channel `user.{userId}` — user ID must match
- [ ] Private channel `extension.{extensionId}` — user's org must match extension's org
- [ ] Authorization endpoint uses `auth:sanctum` middleware
- [ ] No channel pattern allows subscribing to arbitrary organization data

#### 10.2 Broadcast Data Sensitivity
- [ ] `CallInitiated` event data: call_id, from, to, did, status, timestamp — assess PII exposure
- [ ] `CallAnswered` event: call_id, extension, answered_at — minimal PII
- [ ] `CallEnded` event: call_id, duration — minimal PII
- [ ] No sensitive data (passwords, API keys, full CDR) broadcast
- [ ] Events scoped to correct organization channel only

#### 10.3 WebSocket Server Security
- [ ] Soketi app key/secret in environment variables (not hardcoded)
- [ ] Connection rate limiting configured
- [ ] Maximum connections per IP limited
- [ ] Soketi port only accessible via Nginx proxy (not directly exposed)
- [ ] TLS termination at Nginx level for WebSocket connections

#### 10.4 Client-Side Security
- [ ] Echo service sends Bearer token for channel authorization
- [ ] Auto-retry with backoff prevents connection flooding
- [ ] Connection timeout (10s) prevents resource leaks
- [ ] Disconnects on auth failure (prevents ghost connections)

### Testing Methodology
- **Manual**: Subscribe to channels with different auth tokens, verify authorization
- **Penetration**: Attempt to subscribe to other organizations' channels
- **Load testing**: Verify connection limits under pressure

---

## Review Area 11: Compliance & Best Practices

### What to Audit

| Standard | Applicability |
|----------|--------------|
| OWASP Top 10 (2021) | All web application components |
| OWASP API Security Top 10 | REST API endpoints |
| GDPR (EU) | If serving EU users — call records, PII handling |
| TCPA (US) | Auto-dialer campaign compliance |
| PCI DSS | Only if handling payment data (currently out of scope) |
| SOC 2 Type II | Multi-tenant data isolation, audit logging |
| Open Source Security | No secrets in repo, dependency management |

### What to Look For

#### 11.1 OWASP Top 10 Mapping

| OWASP Category | OpBX Coverage | Review Focus |
|----------------|---------------|-------------|
| A01: Broken Access Control | Policies, OrganizationScope | Areas 2, 4 |
| A02: Cryptographic Failures | Encryption, hashing | Areas 5, 6 |
| A03: Injection | SQL, CXML, CSV | Area 3 |
| A04: Insecure Design | Multi-tenancy architecture | Areas 2, 9 |
| A05: Security Misconfiguration | Docker, Nginx, headers | Area 7 |
| A06: Vulnerable Components | Dependencies | Area 8 |
| A07: Auth Failures | Sanctum, webhooks | Areas 1, 4 |
| A08: Data Integrity Failures | CXML responses, webhooks | Areas 3, 4 |
| A09: Logging & Monitoring | AuditLogger, LogSanitizer | Area 5 |
| A10: SSRF | Webhook URLs, recording URLs | Area 3 |

#### 11.2 Open Source Security Checklist
- [ ] `.gitignore` excludes: `.env`, `*.key`, `*.pem`, `credentials*`, `vendor/`, `node_modules/`
- [ ] `.gitignore-security-guide.md` exists and is current
- [ ] No committed secrets in git history (`git log --all -p | grep -i "password\|secret"`)
- [ ] `LICENSE.md` present and correct
- [ ] Security policy (`SECURITY.md` or equivalent) for vulnerability reporting
- [ ] No internal URLs, IPs, or infrastructure details in committed code
- [ ] Docker Compose uses environment variables for all secrets

#### 11.3 Telecommunications Compliance
- [ ] Auto-dialer: caller ID correctly set (not spoofable via API)
- [ ] Auto-dialer: scheduling respects time-of-day restrictions
- [ ] Call recording: announce/consent mechanisms available (conference room settings)
- [ ] CDR retention: policy defined and enforceable
- [ ] Inbound blacklist: audit trail for blocked calls (BlockedCallLog)

#### 11.4 Audit Trail Completeness
- [ ] All CRUD operations on security-sensitive entities are audit-logged
- [ ] Login/logout events logged with IP and user agent
- [ ] Password changes logged (without password values)
- [ ] Settings changes logged (with before/after state, keys masked)
- [ ] Platform management operations logged to `platform_audit_logs`
- [ ] Failed authentication attempts logged
- [ ] Rate limit violations logged

### Testing Methodology
- **Checklist review**: OWASP Top 10 point-by-point verification
- **Git history audit**: Search for accidentally committed secrets
- **Compliance mapping**: Document which standards are met, partially met, or not applicable

---

## Severity Classification Guide

### Critical (CVSS 9.0-10.0)
- Authentication bypass allowing unauthorized access
- Tenant isolation failure (cross-tenant data access)
- Remote code execution
- SQL injection in production query path
- Unencrypted sensitive data transmission
- **Must be fixed before next deployment**

### High (CVSS 7.0-8.9)
- Authorization bypass (privilege escalation within tenant)
- Sensitive data exposure in API responses
- SSRF allowing internal network access
- Missing webhook authentication
- Race condition leading to data corruption
- **Must be fixed within 1 sprint (2 weeks)**

### Medium (CVSS 4.0-6.9)
- Information disclosure (version numbers, stack traces)
- Missing rate limiting on sensitive endpoints
- Weak cryptographic configuration
- Incomplete input validation
- Missing audit logging for security events
- **Should be fixed within 1 month**

### Low (CVSS 0.1-3.9)
- Missing security headers (non-critical)
- Informational disclosure (debug endpoints)
- Documentation gaps
- Best practice deviations with minimal risk
- **Fix when convenient, track in backlog**

### Informational (CVSS 0.0)
- Recommendations for defense-in-depth
- Suggestions for monitoring improvements
- Notes about accepted risks
- **Document for future consideration**

---

## Output Template for Security Review To-Do Document

When a security review is conducted, create the following document at:
```
/docs/review-workplan/security-review-{YYYY-MM-DD}/FINDINGS.md
```

Use this template:

```markdown
# Security Review Results — {YYYY-MM-DD}

**Reviewer:** {name}
**Scope:** {Full / Focused: Areas X, Y, Z}
**Branch/Commit:** {branch name} ({short commit hash})
**Duration:** {hours spent}

---

## Executive Summary

{2-3 sentence overview of findings}

**Overall Security Rating:** {A+ / A / B+ / B / C / D / F}

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | X |
| High | X |
| Medium | X |
| Low | X |
| Informational | X |
| **Total** | **X** |

---

## Critical & High Priority Findings

### [SR-{number}] {Title}
- **Severity:** Critical / High / Medium / Low / Informational
- **CVSS Score:** {X.X} ({vector string if applicable})
- **Category:** {OWASP category or review area}
- **CWE:** CWE-{number} — {name} (if applicable)
- **Affected Component(s):** {file paths, endpoints, services}
- **Description:** {detailed description of the vulnerability or issue}
- **Attack Scenario:**
  {Step-by-step description of how an attacker could exploit this}
- **Evidence:**
  ```{language}
  {Code snippet or configuration showing the issue}
  ```
- **Recommendation:**
  {Specific remediation steps, ideally with code examples}
  ```{language}
  {Fixed code example}
  ```
- **Verification:**
  {How to verify the fix — test case, manual check, or automated scan}
- **Effort:** S / M / L
- **Status:** Open / In Progress / Fixed / Accepted Risk

---

## Medium & Low Priority Findings

{Same format as above, grouped by severity}

---

## Informational Notes

{Same format, abbreviated — no attack scenario needed}

---

## Previously Identified Issues — Status Update

| ID | Title | Previous Severity | Current Status |
|----|-------|-------------------|----------------|
| SR-{n} from {date} | {title} | {severity} | Fixed / Still Open / Regressed |

---

## Recommendations for Next Review

{Areas that need deeper investigation, emerging risks, new modules to cover}

---

*Generated from SECURITY-REVIEW-WORKPLAN.md v1.0*
```

---

## Review Workflow

### Step 1: Scope & Preparation (30 min)
1. Define review scope (full vs. focused)
2. Set up tools (`composer audit`, `npm audit`, etc.)
3. Review previous security review findings
4. Pull latest code and verify build succeeds

### Step 2: Authentication & Access Control (2 hours)
1. Review Areas 1 and 2 (authentication, authorization, tenant isolation)
2. Trace auth flows end-to-end
3. Enumerate all OrganizationScope bypasses
4. Test policy enforcement on all endpoints

### Step 3: Input & API Surface (2 hours)
1. Review Areas 3 and 4 (injection, webhook/API security)
2. Search for raw SQL, string concatenation in XML/HTML
3. Verify middleware coverage on all routes
4. Test SSRF vectors on all URL-accepting endpoints

### Step 4: Data Protection & Cryptography (1.5 hours)
1. Review Areas 5 and 6 (data protection, encryption)
2. Audit all sensitive field storage and transmission
3. Verify log sanitization completeness
4. Check cryptographic configuration

### Step 5: Infrastructure & Dependencies (1 hour)
1. Review Areas 7 and 8 (containers, dependencies)
2. Run dependency vulnerability scans
3. Review Docker/Nginx configurations
4. Verify network exposure

### Step 6: Business Logic & Real-Time (1.5 hours)
1. Review Areas 9 and 10 (race conditions, WebSocket)
2. Trace concurrent access paths
3. Verify distributed lock coverage
4. Test channel authorization

### Step 7: Compliance & Documentation (1 hour)
1. Review Area 11 (OWASP mapping, compliance)
2. Git history audit for secrets
3. Map findings to OWASP categories
4. Document accepted risks

### Step 8: Report Generation (1 hour)
1. Compile findings into to-do document
2. Assign severity ratings (CVSS where applicable)
3. Write remediation recommendations
4. Cross-reference with prior review findings

**Total estimated time for full review: 10-12 hours**

---

## Security Review Cadence

| Review Type | Frequency | Scope |
|-------------|-----------|-------|
| Full security review | Quarterly | All 11 areas |
| Focused review | Per major feature | Areas relevant to feature |
| Dependency scan | Monthly | Area 8 only |
| Pre-release review | Before each release | Areas 1-6, 8 |
| Incident-driven | After any security event | Targeted investigation |

---

## References

- [OWASP Top 10 (2021)](https://owasp.org/www-project-top-ten/)
- [OWASP API Security Top 10](https://owasp.org/www-project-api-security/)
- [CWE/SANS Top 25](https://cwe.mitre.org/top25/)
- [Laravel Security Best Practices](https://laravel.com/docs/12.x/security)
- [Cloudonix Docs](https://developers.cloudonix.com/)
- [Prior Security Review](/docs/workplans/security-code-review-report.md) — Feb 2026
- [Prior Combined Review](/docs/FULL-CODE-AND-SECURITY-REVIEW-2026-02-06.md) — Feb 2026
- [AGENTS.md](/AGENTS.md) — Project coding standards
- [Security Memory File](/memory/security.md) — Module documentation

---

**Document Version**: 1.0
**Last Updated**: 2026-04-09
**Maintained By**: Security Review Team
