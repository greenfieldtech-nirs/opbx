# OpenPBX Security Remediation Work Plan

**Based On:** Security Code Review Report (February 1, 2026)  
**Total Issues:** 15 (2 Critical, 5 High, 6 Medium, 2 Low)  
**Estimated Effort:** 40-60 hours

---

## Phase 1: Critical Issues (Week 1)

**Goal:** Fix authentication bypass and race conditions  
**Effort:** 16-20 hours

### 1.1 Fix Voice Webhook Authentication Bypass

**Priority:** CRITICAL  
**Estimated Hours:** 8-10

#### Tasks

##### Task 1.1.1: Refactor Token Identification and Verification
```php
// BEFORE (problematic):
$organizationId = $this->identifyOrganizationByToken($providedToken);
$identifiedByToken = $organizationId !== null;
if (!$identifiedByToken) {
    // This check is meaningless - token was never verified!
    if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
        return $this->unauthorizedResponse();
    }
}

// AFTER (fixed):
// Always verify token if provided
$organizationId = null;
$tokenVerified = false;

if ($providedToken) {
    $settings = CloudonixSettings::where('domain_requests_api_key', $providedToken)->first();
    if ($settings) {
        $organizationId = $settings->organization_id;
        $tokenVerified = true;
    }
}

// Only proceed with fallback if token verification failed
if (!$tokenVerified && $cxApiKey) {
    $settings = CloudonixSettings::where('domain_requests_api_key', $cxApiKey)->first();
    if ($settings) {
        $organizationId = $settings->organization_id;
        $tokenVerified = true;
    }
}
```

##### Task 1.1.2: Remove Domain-Only Identification Bypass
- Add requirement for token-based authentication for voice routing
- Domain identification should only be used for initial organization lookup, not authentication

##### Task 1.1.3: Add Token Verification for All Paths
- Ensure Bearer token is verified regardless of identification method
- Add unit tests for all authentication paths

#### Files to Modify
- `app/Http/Middleware/VerifyVoiceWebhookAuth.php`

#### Verification Criteria
- [ ] All authentication paths verify tokens
- [ ] No bypass possible through domain spoofing
- [ ] Unit tests cover all authentication scenarios

---

### 1.2 Fix Race Condition in Owner Deletion

**Priority:** CRITICAL  
**Estimated Hours:** 8-10

#### Tasks

##### Task 1.2.1: Implement Database-Level Constraint
```php
// Migration to add CHECK constraint
Schema::table('users', function (Blueprint $table) {
    // Add trigger-like constraint via application logic
});
```

##### Task 1.2.2: Use Transaction with Locking Select
```php
// BEFORE:
$ownerCount = User::forOrganization($currentUser->organization_id)
    ->withRole(UserRole::OWNER)
    ->count();

if ($ownerCount <= 1) {
    return response()->json([...], 409);
}
$model->delete();

// AFTER:
DB::transaction(function () use ($currentUser, $model) {
    $lockKey = "lock:org:{$currentUser->organization_id}:owner_count";
    $lock = Cache::lock($lockKey, 10)->block(5);
    
    try {
        $ownerCount = User::forOrganization($currentUser->organization_id)
            ->withRole(UserRole::OWNER)
            ->lock()  // Database-level lock
            ->count();
        
        if ($ownerCount <= 1) {
            throw new \Exception('Cannot delete the last owner');
        }
        
        $model->delete();
    } finally {
        $lock->release();
    }
});
```

##### Task 1.2.3: Add Owner Deletion Queue Job for High Contention
- Move owner deletion to queued job for organizations with many concurrent admins
- Use Redis-based distributed locking

#### Files to Modify
- `app/Http/Controllers/Api/UsersController.php`
- Database migration for CHECK constraint

#### Verification Criteria
- [ ] Two concurrent owner deletions both fail (expected behavior)
- [ ] Lock acquisition timeout handled gracefully
- [ ] Unit tests verify race condition handling

---

## Phase 2: High Priority Issues (Weeks 2-3)

**Goal:** Fix token permissions, logging, and data exposure  
**Effort:** 16-20 hours

### 2.1 Implement Scoped Tokens

**Priority:** HIGH  
**Estimated Hours:** 6-8

#### Tasks

##### Task 2.1.1: Define Token Scopes Based on Roles
```php
// In AuthController.php

private function getTokenAbilities(User $user): array
{
    return match ($user->role) {
        UserRole::OWNER => [
            'extension:*',
            'user:*',
            'ring-group:*',
            'did-number:*',
            'recording:*',
            'settings:*',
            'business-hours:*',
        ],
        UserRole::PBX_ADMIN => [
            'extension:*',
            'user:*',
            'ring-group:*',
            'did-number:*',
            'recording:read',
            'business-hours:*',
        ],
        UserRole::PBX_USER => [
            'extension:read',
            'extension:update:own',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
        ],
        UserRole::REPORTER => [
            'extension:read',
            'user:read',
            'ring-group:read',
            'did-number:read',
            'recording:read',
            'call-log:read',
        ],
    };
}
```

##### Task 2.1.2: Update Token Creation
```php
// Replace wildcard ['*'] with scoped abilities
$abilities = $this->getTokenAbilities($user);
$token = $user->createToken-token',
    $(
    'apiabilities,
    now()->addMinutes(self::TOKEN_EXPIRATION_MINUTES)
)->plainTextToken;
```

##### Task 2.1.3: Update Policy Checks for Scoped Tokens
- Review all Policy classes to use ability-based checks
- Add `can()` checks for specific abilities

#### Files to Modify
- `app/Http/Controllers/Api/AuthController.php`
- `app/Policies/*.php`

#### Verification Criteria
- [ ] Tokens have role-appropriate scopes
- [ ] Token with 'extension:read' cannot modify extensions
- [ ] Owner token has full access

---

### 2.2 Fix Token Logging Issues

**Priority:** HIGH  
**Estimated Hours:** 4-6

#### Tasks

##### Task 2.2.1: Sanitize Authorization Header in Logs
```php
// Add to VerifyVoiceWebhookAuth.php
$request->headers->remove('Authorization');
// Or use a logging sanitization middleware
```

##### Task 2.2.2: Remove Token Prefix Logging
- Remove `'bearer_token_prefix' => substr($providedToken, 0, 10) . '...'`
- Remove any logging that exposes partial tokens

##### Task 2.2.3: Remove Debug Logging of CloudonixSettings
- Remove the code that logs all CloudonixSettings for debugging

#### Files to Modify
- `app/Http/Middleware/VerifyVoiceWebhookAuth.php`

#### Verification Criteria
- [ ] No token parts in logs
- [ ] No settings data in debug logs
- [ ] Logs still provide useful debugging information

---

### 2.3 Remove Passwords from API Responses

**Priority:** HIGH  
**Estimated Hours:** 4-6

#### Tasks

##### Task 2.3.1: Mask Password in VoiceAgentResource
```php
// In VoiceAgentResource.php
'password' => $this->whenAppended('masked_password', '***'),
```

##### Task 2.3.2: Remove Password from Extension Password Endpoint
- Review if password retrieval is necessary
- If needed, return only the password once then mark as "retrieved"
- Add audit logging for password access

##### Task 2.3.3: Add Password Field Protection
```php
// In VoiceAgent model
protected $hidden = ['password'];
```

#### Files to Modify
- `app/Models/VoiceAgent.php`
- `app/Http/Resources/VoiceAgentResource.php`
- `app/Http/Controllers/Api/ExtensionPasswordController.php`

#### Verification Criteria
- [ ] No passwords in API responses
- [ ] Audit log tracks password access
- [ ] Hidden fields are properly protected

---

## Phase 3: Medium Priority Issues (Weeks 4-5)

**Goal:** Fix race conditions, input validation, and data isolation  
**Effort:** 12-16 hours

### 3.1 Fix Idempotency Key Generation

**Priority:** MEDIUM  
**Estimated Hours:** 4-6

#### Tasks

##### Task 3.1.1: Make JSON Encoding Deterministic
```php
// In EnsureWebhookIdempotency.php
private function getIdempotencyKey(Request $request): ?string
{
    // Use JSON_PRESERVE_ZERO_FRICTION for consistent ordering
    $payload = $request->all();
    if (!empty($payload)) {
        return hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRICTION));
    }
    return null;
}
```

##### Task 3.1.2: Use Full SHA-256 Hash
- Remove any truncation of the hash
- Use full 64-character hex string

##### Task 3.1.3: Add Timestamp to Idempotency Key
```php
$timestamp = $request->input('timestamp', time());
return hash('sha256', $callId . ':' . $eventType . ':' . $timestamp);
```

#### Files to Modify
- `app/Http/Middleware/EnsureWebhookIdempotency.php`

#### Verification Criteria
- [ ] Same payload produces same idempotency key
- [ ] No hash collisions in testing
- [ ] Timestamp prevents duplicate processing

---

### 3.2 Fix Rate Limiting Race Condition

**Priority:** MEDIUM  
**Estimated Hours:** 4-6

#### Tasks

##### Task 3.2.1: Use Atomic Redis Operations
```php
// In RateLimitPerOrganization.php
private function incrementAttempts(string $key, int $minutes): int
{
    $attempts = Cache::increment($key);
    if ($attempts === 1) {
        Cache::expire($key, $minutes * 60);
    }
    return $attempts;
}
```

##### Task 3.2.2: Add Redis Connection Health Check
- If Redis fails, degrade gracefully
- Log degradation for monitoring

#### Files to Modify
- `app/Http/Middleware/RateLimitPerOrganization.php`

#### Verification Criteria
- [ ] Rate limiting works under concurrent load
- [ ] No double-counting under race conditions
- [ ] Graceful degradation if Redis unavailable

---

### 3.3 Fix Recording Token URL Exposure

**Priority:** MEDIUM  
**Estimated Hours:** 2-4

#### Tasks

##### Task 3.3.1: Move Token to Authorization Header
```javascript
// Frontend API change
export const downloadRecording = async (recordingId: string, token: string) => {
  const response = await api.get(`/recordings/${recordingId}/download`, {
    headers: {
      'Authorization': `Bearer ${token}`
    },
    responseType: 'blob'
  });
  return response;
};
```

##### Task 3.3.2: Update Backend Endpoint
```php
// In RecordingsController.php
public function download(Request $request, DidNumber $recording): Response
{
    $token = $request->bearerToken();
    // ... validation logic
}
```

#### Files to Modify
- `app/Http/Controllers/Api/RecordingsController.php`
- Frontend API integration

#### Verification Criteria
- [ ] Token passed in Authorization header
- [ ] Download works with new method
- [ ] Query string no longer used for token

---

### 3.4 Add Input Sanitization

**Priority:** MEDIUM  
**Estimated Hours:** 2-4

#### Tasks

##### Task 3.4.1: Sanitize Extension Names
```php
// In StoreExtensionRequest.php
'name' => [
    'required',
    'string',
    'max:100',
    'regex:/^[a-zA-Z0-9\s\-_]+$/',  // Allow safe characters
],
```

##### Task 3.4.2: Add CXML Output Encoding
```php
// In VoiceRoutingController.php
private function escapeCxml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1, 'UTF-8');
}
```

#### Files to Modify
- `app/Http/Requests/Extension/StoreExtensionRequest.php`
- `app/Http/Requests/Extension/UpdateExtensionRequest.php`
- `app/Http/Controllers/Voice/*.php`

#### Verification Criteria
- [ ] Extension names validated against regex
- [ ] CXML responses properly escaped
- [ ] No XSS vulnerabilities in voice responses

---

## Phase 4: Low Priority Issues (Week 6)

**Goal:** Polish security configuration and add missing validations  
**Effort:** 4-6 hours

### 4.1 Organization Validation in me() Endpoint

**Priority:** LOW  
**Estimated Hours:** 2

Add explicit organization validation in the `me()` endpoint for consistency.

### 4.2 HSTS Preload Configuration

**Priority:** LOW  
**Estimated Hours:** 2

Add configuration for HSTS preload eligibility in production.

### 4.3 Remove Deprecated Headers

**Priority:** LOW  
**Estimated Hours:** 1

Remove `X-XSS-Protection` header as it's deprecated.

### 4.4 Password Audit Logging

**Priority:** LOW  
**Estimated Hours:** 2

Remove password from audit logs and add structured audit logging for password access.

---

## Testing Plan

### Unit Tests to Add

```php
// Tests/Unit/Security/AuthenticationTest.php
class AuthenticationTest extends TestCase
{
    public function test_voice_webhook_token_is_always_verified()
    {
        // Test that token verification occurs regardless of identification method
    }
    
    public function test_owner_deletion_race_condition_handled()
    {
        // Simulate concurrent owner deletion attempts
    }
    
    public function test_idempotency_key_is_deterministic()
    {
        // Verify same payload produces same key
    }
    
    public function test_rate_limiter_handles_concurrent_requests()
    {
        // Verify no race conditions in rate limiting
    }
}

// Tests/Unit/Security/TokenScopesTest.php
class TokenScopesTest extends TestCase
{
    public function test_owner_has_full_access()
    {
        // Verify owner token abilities
    }
    
    public function test_pbx_user_cannot_modify_other_extensions()
    {
        // Verify scoped token restrictions
    }
}
```

### Integration Tests to Add

```php
// Tests/Feature/Security/WebhookAuthenticationTest.php
class WebhookAuthenticationTest extends TestCase
{
    public function test_cdr_webhook_with_valid_domain_uuid()
    {
        // Test CDR authentication
    }
    
    public function test_voice_webhook_rejects_invalid_token()
    {
        // Test voice webhook rejects unauthorized tokens
    }
}
```

---

## Rollback Plan

### Database Migrations
- Always create down() method for migrations
- Test rollback in staging environment

### Feature Flags
- Consider feature flags for authentication changes
- Quick rollback via configuration

### Monitoring
- Monitor error rates after changes
- Set up alerts for authentication failures
- Track rate limit exceedances

---

## Success Criteria

### Phase 1 (Critical)
- [ ] Authentication bypass vulnerability fixed
- [ ] No race conditions in owner deletion
- [ ] All unit tests pass
- [ ] Code reviewed by second security engineer

### Phase 2 (High)
- [ ] Tokens have role-appropriate scopes
- [ ] No sensitive data in logs
- [ ] No passwords in API responses
- [ ] Integration tests pass

### Phase 3 (Medium)
- [ ] Idempotency keys deterministic
- [ ] Rate limiting atomic
- [ ] Recording tokens secure
- [ ] Input validated and sanitized

### Phase 4 (Low)
- [ ] Security headers optimized
- [ ] Audit logging complete
- [ ] Documentation updated

---

## Timeline

| Phase | Duration | Start | End |
|-------|----------|-------|-----|
| Phase 1: Critical | 1 week | Week 1 | Week 1 |
| Phase 2: High | 2 weeks | Week 2 | Week 3 |
| Phase 3: Medium | 2 weeks | Week 4 | Week 5 |
| Phase 4: Low | 1 week | Week 6 | Week 6 |

**Total Estimated Timeline:** 6 weeks  
**Total Estimated Hours:** 40-60 hours

---

## References

- **Security Code Review Report:** `docs/workplans/security-code-review-report.md`
- **Laravel Security Documentation:** https://laravel.com/docs/security
- **OWASP API Security:** https://owasp.org/API-Security/
- **Cloudonix Webhook Security:** https://developers.cloudonix.com/Documentation/apiSecurity

---

**Document Version:** 1.0  
**Last Updated:** February 1, 2026  
**Next Review:** May 1, 2026
