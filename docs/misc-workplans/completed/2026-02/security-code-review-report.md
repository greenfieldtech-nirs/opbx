# OpenPBX Security Code Review Report

**Date:** February 1, 2026  
**Reviewer:** Gil Tzur (Senior Developer, Architect & Security Expert)  
**Scope:** Backend (Laravel/PHP) + Frontend (React/TypeScript)  
**Classification:** Internal - Security Sensitive

---

## Executive Summary

This comprehensive security code review of the OpenPBX project identified **15 security issues** across multiple categories:

| Severity | Count | Description |
|----------|-------|-------------|
| Critical | 2 | Webhook authentication bypass, race condition in owner deletion |
| High | 5 | Token exposure in logs, weak rate limiting, sensitive data in responses |
| Medium | 6 | Missing input sanitization, logging of sensitive data |
| Low | 2 | Information disclosure, CSP improvement opportunities |

**Overall Security Rating:** **B+** (Good, but requires attention to critical items)

---

## 1. Authentication & Authorization Issues

### 1.1 CRITICAL: Voice Webhook Authentication Fallback Chain Weakness

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`  
**Lines:** 88-130, 162-188

**Issue:** The voice webhook authentication has multiple fallback mechanisms that could allow unauthorized access:

1. **Bearer token identification is not validated when identifying by token** (line 96-97, 162-188):
   - When organization is identified by token, the code sets `$identifiedByToken = true` but never actually verifies the token matches
   - This allows any token to work if it happens to match an organization's `domain_requests_api_key`

```php
$organizationId = $this->identifyOrganizationByToken($providedToken);
$identifiedByToken = $organizationId !== null;  // ⚠️ Just checks if org found, not if token matches!

// If Bearer token didn't work, try X-Cx-Apikey header
if (!$organizationId && $cxApiKey) {
    $organizationId = $this->identifyOrganizationByToken($cxApiKey);
    $identifiedByToken = $organizationId !== null;  // ⚠️ Same issue
}

// Later in validation...
if (!$identifiedByToken) {  // ⚠️ This check is now meaningless
    if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
        return $this->unauthorizedResponse();
    }
}
```

2. **Domain-based identification bypasses token verification** (lines 114-119):
   - If organization is identified by domain, token verification is skipped entirely
   - An attacker could spoof domain headers to access any organization

**Impact:** Severe - Attackers could route calls through any organization's PBX, potentially causing financial loss and service abuse.

**Recommendation:** 
- Always verify token when it's provided, regardless of identification method
- Add explicit token verification for all authentication paths
- Require Bearer token for voice routing endpoints

---

### 1.2 HIGH: Bearer Token with Wildcard Permissions

**File:** `app/Http/Controllers/Api/AuthController.php`  
**Lines:** 164-168, 361-365

**Issue:** API tokens are created with wildcard permissions `['*']`:

```php
$token = $user->createToken(
    'api-token',
    ['*'],  // ⚠️ Wildcard - grants all permissions
    now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
)->plainTextToken;
```

**Impact:** If a token is compromised, the attacker has full access to all API endpoints regardless of the user's actual role.

**Recommendation:**
- Implement scoped tokens based on user role
- Use ability-based Sanctum tokens: `['extension:*', 'user:read']`
- Consider token segmentation for different use cases

---

### 1.3 HIGH: Token Prefix Logging in Production

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`  
**Lines:** 39-43, 88-94, 139

**Issue:** Token prefixes are logged in production:

```php
Log::info('Voice webhook auth middleware triggered', [
    'ip' => $request->ip(),
    'path' => $request->path(),
    'method' => $request->method(),
    'headers' => $request->headers->all(),  // ⚠️ Logs Authorization header
]);

// Also:
'bearer_token_prefix' => substr($providedToken, 0, 10) . '...',  // ⚠️ Logs partial token
```

**Impact:** Partial tokens in logs could be used for token reconstruction attacks if multiple requests are logged.

**Recommendation:**
- Sanitize logs to remove Authorization headers
- Never log any part of authentication tokens
- Use structured logging with sensitive field redaction

---

### 1.4 MEDIUM: Missing Organization Check in `me()` Endpoint

**File:** `app/Http/Controllers/Api/AuthController.php`  
**Lines:** 285-306

**Issue:** The `me()` endpoint returns organization details without verifying organization ownership:

```php
return response()->json([
    'user' => [
        'id' => $user->id,
        'organization_id' => $user->organization_id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
        'status' => $user->status,
        'organization' => [
            'id' => $user->organization->id,
            'name' => $user->organization->name,
            'slug' => $user->organization->slug,
            'status' => $user->organization->status,
            'timezone' => $user->organization->timezone,
        ],
    ],
]);
```

**Impact:** Low - The user is authenticated, but organization data should still be validated for tenant isolation.

**Recommendation:** Add explicit organization validation in the `me()` endpoint.

---

## 2. Race Condition Issues

### 2.1 CRITICAL: Race Condition in Last Owner Deletion Check

**File:** `app/Http/Controllers/Api/UsersController.php`  
**Lines:** 156-173

**Issue:** The owner deletion check has a TOCTOU (Time-of-Check-Time-of-Use) race condition:

```php
$ownerCount = User::forOrganization($currentUser->organization_id)
    ->withRole(UserRole::OWNER)
    ->count();

if ($ownerCount <= 1) {
    // Block deletion
    return response()->json([...], 409);
}

// ⚠️ RACE WINDOW: Another request could delete the last owner between check and delete
$model->delete();
```

**Impact:** Severe - If two admins attempt to delete the last owner simultaneously, both checks could pass, leaving the organization without an owner.

**Recommendation:**
- Use database-level constraint: `ALTER TABLE users ADD CONSTRAINT min_one_owner CHECK (organization_id NOT IN (SELECT organization_id FROM users WHERE role = 'owner' GROUP BY organization_id HAVING COUNT(*) < 1))`
- Use `DB::transaction()` with locking SELECT
- Implement advisory locks for owner deletion operations

---

### 2.2 MEDIUM: Idempotency Key Generation Collision Potential

**File:** `app/Http/Middleware/EnsureWebhookIdempotency.php`  
**Lines:** 200-225

**Issue:** Idempotency key generation has potential for collisions:

```php
// If CallSid or call_id not available, falls back to full payload hash
if ($callId && $eventType) {
    return hash('sha256', $callId . ':' . $eventType);  // ⚠️ Short hash
}

// Generate from full payload as last resort
$payload = $request->all();
if (!empty($payload)) {
    return hash('sha256', json_encode($payload));  // ⚠️ Non-deterministic JSON encoding
}
```

**Issues:**
1. JSON encoding is not deterministic (key order may vary)
2. SHA-256 truncation increases collision probability

**Impact:** Medium - Could cause legitimate webhook duplication or incorrect deduplication.

**Recommendation:**
- Use `json_encode($payload, JSON_PRESERVE_ZERO_FRICTION)` for deterministic encoding
- Use full SHA-256 hash (32 bytes)
- Add timestamp to idempotency key for additional uniqueness

---

### 2.3 MEDIUM: Rate Limiting Counter Race Condition

**File:** `app/Http/Middleware/RateLimitPerOrganization.php`  
**Lines:** 141-159

**Issue:** The rate limiting counter uses non-atomic increment operations:

```php
$current = Cache::get($key);
if ($current === null) {
    Cache::put($key, 1, now()->addMinutes($minutes));
    return 1;
}
$attempts = $current + 1;  // ⚠️ RACE: Another request could read same value
Cache::put($key, $attempts, now()->addMinutes($minutes));  // ⚠️ Overwrites
```

**Impact:** Medium - In high-concurrency scenarios, rate limits could be bypassed.

**Recommendation:** Use Redis atomic operations:
```php
$attempts = Cache::increment($key);
if ($attempts === 1) {
    Cache::expire($key, $minutes * 60);
}
```

---

## 3. Data Isolation & Privacy Issues

### 3.1 HIGH: Sensitive Data in API Responses

**Files:** 
- `app/Models/VoiceAgent.php` (lines 169, 190)
- `app/Http/Controllers/Api/ExtensionPasswordController.php` (line 73)

**Issue:** Passwords are included in API responses:

```php
// VoiceAgent.php
'password' => $this->password,  // ⚠️ Returns encrypted password in API

// ExtensionPasswordController.php
'password' => $extension->password,  // ⚠� Returns plain text password!
```

**Impact:** High - Passwords exposed in API responses could be intercepted.

**Recommendation:**
- Never return passwords in API responses
- For VoiceAgent, use accessor that returns masked value: `'password' => '***'`
- For ExtensionPasswordController, review if password needs to be shown at all

---

### 3.2 HIGH: Cloudonix API Key Exposure in Logs

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`  
**Lines:** 226-237

**Issue:** All Cloudonix settings are logged for debugging:

```php
$allSettings = CloudonixSettings::all(['id', 'organization_id', 'domain_name', 'domain_uuid']);
Log::info('Voice Webhook: All CloudonixSettings records', [
    'count' => $allSettings->count(),
    'settings' => $allSettings->map(function($s) {
        return [
            'id' => $s->id,
            'org_id' => $s->organization_id,
            'domain' => $s->domain_name,
            'uuid' => $s->domain_uuid,
        ];
    })->toArray(),
]);
```

**Impact:** High - If `domain_requests_api_key` is accidentally included, it would be logged.

**Recommendation:** Remove this debug logging or ensure only safe fields are logged.

---

### 3.3 MEDIUM: Recording Download Token Contains Sensitive Data

**File:** `app/Services/Recording/RecordingAccessService.php`  
**Issue:** Recording access tokens contain user ID and are stored in URL query parameters:

```php
// Token generated and passed in URL
'download_url' => '/api/v1/recordings/download?token='.urlencode($token),

// Token decrypted for logging
$decrypted = \Illuminate\Support\Facades\Crypt::decryptString($token);
// Log contains user identification
```

**Impact:** Medium - Token in URL could be leaked via:
- Browser history
- Referer headers
- Server access logs

**Recommendation:**
- Use Authorization header for token delivery
- Consider shorter token lifetimes
- Ensure server logs don't capture query strings

---

### 3.4 LOW: Phone Number Normalization Bypass

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`  
**Lines:** 361-371

**Issue:** Phone number normalization only removes formatting characters but doesn't validate format:

```php
private function normalizePhoneNumber(?string $number): ?string
{
    if (!$number) {
        return null;
    }
    // Remove non-numeric characters except +
    $number = preg_replace('/[^0-9+]/', '', $number);
    return $number;  // ⚠️ No format validation
}
```

**Impact:** Low - Could accept malformed phone numbers for organization identification.

**Recommendation:** Add E.164 format validation.

---

## 4. Input Validation Issues

### 4.1 MEDIUM: CXML Output Not Properly Escaped

**File:** `app/Http/Controllers/Voice/VoiceRoutingController.php`  
**Issue:** CXML responses may contain unescaped user-controlled data.

**Impact:** Medium - Potential for CXML injection if user-controlled data (extension names, etc.) is included in responses.

**Recommendation:**
- Use `htmlspecialchars()` for all dynamic content in CXML
- Implement output encoding on all controller responses
- Add Content-Type validation for voice endpoints

---

### 4.2 MEDIUM: Missing Input Sanitization in Extension Names

**Files:**
- `app/Http/Requests/Extension/StoreExtensionRequest.php`
- `app/Http/Requests/Extension/UpdateExtensionRequest.php`

**Issue:** Extension name validation allows special characters:

```php
'name' => ['required', 'string', 'max:100'],  // ⚠️ No sanitization
```

**Impact:** Medium - Could allow injection of control characters or formatting that affects displays.

**Recommendation:**
- Add sanitization for extension names
- Consider restricting to safe character sets
- Implement length limits consistent with database constraints

---

## 5. Security Configuration Issues

### 5.1 LOW: HSTS Header Not Configured for Subdomains

**File:** `app/Http/Middleware/SecurityHeaders.php`  
**Lines:** 119-133

**Issue:** HSTS configuration requires explicit opt-in for subdomains:

```php
if (config('security.hsts_include_subdomains', true)) {
    $hstsDirectives[] = 'includeSubDomains';
}
```

**Impact:** Low - Without HSTS preload, subdomain hijacking remains possible.

**Recommendation:** Consider enabling HSTS preload for production deployments.

---

### 5.2 LOW: X-XSS-Protection Header is Deprecated

**File:** `app/Http/Middleware/SecurityHeaders.php`  
**Line:** 136

**Issue:** Legacy X-XSS-Protection header is still sent:

```php
$response->headers->set('X-XSS-Protection', '1; mode=block');
```

**Impact:** Low - Header is deprecated and provides no additional protection.

**Recommendation:** Remove deprecated header, rely on CSP.

---

## 6. Security Best Practices Issues

### 6.1 HIGH: Redis Without Password in Default Configuration

**File:** `app/Providers/AppServiceProvider.php`  
**Lines:** 87-93

**Issue:** Redis runs without password in default configuration:

```php
if (empty(config('database.redis.default.password'))) {
    Log::critical('SECURITY WARNING: Redis password not set in production!', [
        // Warning only - no enforcement
    ]);
}
```

**Impact:** High - Redis accessible without authentication could expose:
- Session data
- Rate limiting counters
- Idempotency keys
- Cached webhook responses

**Recommendation:**
- Make Redis password mandatory in production
- Use environment variable validation
- Block startup if Redis password not set in production

---

### 6.2 MEDIUM: Generic Error Messages Inconsistent

**Files:**
- `app/Http/Controllers/Api/AuthController.php` (line 80-86)
- `app/Http/Middleware/VerifyCloudonixSignature.php` (lines 100-122)

**Issue:** Error messages are generic but inconsistently applied:

```php
// Good - generic error
return response()->json([
    'error' => 'Unauthorized - Missing signature',
], Response::HTTP_UNAUTHORIZED);
```

**Impact:** Medium - Inconsistent error handling could leak information.

**Recommendation:** Standardize error response format across all authentication endpoints.

---

### 6.3 LOW: Missing Audit Logging for Sensitive Operations

**File:** `app/Http/Controllers/Api/ExtensionPasswordController.php`  
**Lines:** 73-74, 125-126

**Issue:** Password exposure in API response lacks audit logging:

```php
Log::info('Extension password retrieved', [
    'password' => $extension->password,  // ⚠️ Logs password!
    'warning' => 'This password is only shown once. Store it securely.',
]);
```

**Impact:** Low - Password in logs creates exposure window.

**Recommendation:**
- Remove password from log
- Add structured audit log for password access
- Consider if password retrieval is necessary

---

## 7. Summary of Recommendations by Priority

### Critical (Fix Within 1 Week)

| ID | Issue | File | Line | Remediation |
|----|-------|------|------|-------------|
| 1.1 | Voice webhook authentication bypass | VerifyVoiceWebhookAuth.php | 88-130 | Always verify tokens, never skip verification |
| 2.1 | Race condition in owner deletion | UsersController.php | 156-173 | Use DB transaction with locking |

### High (Fix Within 2 Weeks)

| ID | Issue | File | Line | Remediation |
|----|-------|------|------|-------------|
| 1.2 | Wildcard token permissions | AuthController.php | 164-168 | Implement scoped tokens |
| 1.3 | Token prefix logging | VerifyVoiceWebhookAuth.php | 39-43 | Sanitize logs |
| 3.1 | Passwords in API responses | VoiceAgent.php, ExtensionPasswordController.php | Various | Never return passwords |
| 3.2 | API keys in debug logs | VerifyVoiceWebhookAuth.php | 226-237 | Remove debug logging |
| 6.1 | Redis without password | AppServiceProvider.php | 87-93 | Make password mandatory |

### Medium (Fix Within 1 Month)

| ID | Issue | File | Line | Remediation |
|----|-------|------|------|-------------|
| 2.2 | Idempotency key collisions | EnsureWebhookIdempotency.php | 200-225 | Deterministic JSON, full hash |
| 2.3 | Rate limiting race | RateLimitPerOrganization.php | 141-159 | Use atomic operations |
| 3.3 | Recording token in URL | RecordingAccessService.php | - | Use Authorization header |
| 3.4 | Phone number validation | VerifyVoiceWebhookAuth.php | 361-371 | Add E.164 validation |
| 4.1 | CXML output encoding | VoiceRoutingController.php | - | Use htmlspecialchars() |
| 4.2 | Extension name sanitization | StoreExtensionRequest.php | - | Add input sanitization |

### Low (Fix Within 3 Months)

| ID | Issue | File | Line | Remediation |
|----|-------|------|------|-------------|
| 1.4 | Missing org check in me() | AuthController.php | 285-306 | Add validation |
| 5.1 | HSTS subdomains opt-in | SecurityHeaders.php | 119-133 | Enable preload |
| 5.2 | X-XSS-Protection header | SecurityHeaders.php | 136 | Remove deprecated header |
| 6.3 | Password in audit logs | ExtensionPasswordController.php | 73 | Remove from log |

---

## 8. Testing Recommendations

### 8.1 Unit Tests Required

1. **Authentication Flow Tests**
   - Test all authentication fallback paths
   - Verify token validation is always performed
   - Test with invalid/expired tokens

2. **Race Condition Tests**
   - Simulate concurrent owner deletion attempts
   - Test idempotency key collision scenarios
   - Verify rate limiting under concurrent load

3. **Data Isolation Tests**
   - Cross-tenant access attempts
   - Organization scope enforcement
   - Extension ownership verification

### 8.2 Integration Tests Required

1. **Webhook Authentication**
   - CDR webhook authentication with valid/invalid domain UUID
   - Voice webhook authentication with all fallback methods
   - Signature verification with various payloads

2. **Recording Access**
   - Token-based access validation
   - Token expiration handling
   - Cross-tenant recording access attempts

---

## 9. Conclusion

The OpenPBX codebase demonstrates **good security posture** overall with:
- ✅ Proper webhook signature verification
- ✅ Tenant isolation via OrganizationScope
- ✅ Comprehensive rate limiting
- ✅ Security headers middleware
- ✅ Input validation via Form Requests

However, **2 critical issues** require immediate attention:
1. Voice webhook authentication bypass (1.1)
2. Race condition in owner deletion (2.1)

Addressing these issues along with the high-priority items will significantly improve the security posture of the platform.

---

**Report Prepared By:** Gil Tzur  
**Review Date:** February 1, 2026  
**Next Review:** May 1, 2026
