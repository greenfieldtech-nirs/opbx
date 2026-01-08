# Issue #9: Input Validation Gaps in Search Parameters

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 1-2 hours
**Assigned:** Unassigned

## Problem Description

Search parameters in `UsersController` are accepted without validation, potentially enabling DoS attacks or information disclosure.

**Location:** `app/Http/Controllers/Api/UsersController.php:275`, `app/Models/User.php:scopeSearch`

## Impact Assessment

- **Severity:** Important - Security implications
- **Scope:** User search functionality
- **Risk:** Medium - Potential DoS or information disclosure
- **Dependencies:** Request validation, model scopes

## Solution Overview

Implement proper input validation for search parameters and sanitize database queries.

## Implementation Steps

### Step 1: Create Validation Class
1. Create `UserIndexRequest` with proper validation rules
2. Add length limits and character restrictions
3. Implement the validation in controller

### Step 2: Update Model Scope
1. Sanitize search input in `scopeSearch` method
2. Add length limits and escape special characters
3. Ensure safe database queries

### Step 3: Add Rate Limiting
1. Implement rate limiting for search endpoints
2. Add appropriate headers

### Step 4: Test Implementation
1. Test with malicious input
2. Verify validation works
3. Check performance impact

## Code Changes

### New File: `app/Http/Requests/UserIndexRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\s@._-]+$/'],
            'role' => ['nullable', 'string', Rule::in(UserRole::values())],
            'status' => ['nullable', 'string', Rule::in(UserStatus::values())],
            'sort' => ['nullable', 'string', Rule::in(['name', 'email', 'created_at', 'role', 'status'])],
            'order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
```

### File: `app/Http/Controllers/Api/UsersController.php`
**Before:**
```php
public function index(Request $request)
```
**After:**
```php
public function index(UserIndexRequest $request)
```

### File: `app/Models/User.php`
```php
public function scopeSearch($query, string $search)
{
    // Trim and limit search string
    $search = trim(substr($search, 0, 100));

    if (empty($search)) {
        return $query;
    }

    return $query->where(function ($q) use ($search) {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%");
    });
}
```

## Verification Steps

1. **Validation Test:**
   ```bash
   # Test with malicious input
   curl "/api/users?search=<script>alert('xss')</script>"
   ```
   Expected: Validation error or sanitized

2. **Performance Test:**
   ```bash
   # Test with long strings
   curl "/api/users?search=$(python3 -c "print('A'*1000)")"
   ```
   Expected: Limited input length

3. **Database Query Test:**
   - Verify no SQL injection possible
   - Check query performance with large datasets

## Rollback Plan

If validation causes issues:
1. Temporarily disable strict validation
2. Implement simpler sanitization
3. Monitor for breaking changes

## Testing Requirements

- [ ] Malicious input rejected
- [ ] Search functionality works
- [ ] Performance not degraded
- [ ] Rate limiting active

## Documentation Updates

- Update API documentation with validation rules
- Document security improvements
- Mark as completed in master work plan

## Completion Criteria

- [ ] Input validation implemented
- [ ] Malicious input blocked
- [ ] Search functionality preserved
- [ ] Code reviewed and approved

---

**Estimated Completion:** 1-2 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #10: Weak Password Policy

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

Password policy only requires 8 characters with no complexity rules, making accounts vulnerable to cracking.

**Location:** `app/Http/Requests/Auth/RegisterRequest.php`

## Impact Assessment

- **Severity:** Important - Security vulnerability
- **Scope:** User authentication
- **Risk:** Medium - Weak passwords can be cracked
- **Dependencies:** Laravel validation, user registration

## Solution Overview

Implement strong password requirements with complexity rules and breach checking.

## Implementation Steps

### Step 1: Update Validation Rules
1. Increase minimum length to 12 characters
2. Add complexity requirements (uppercase, lowercase, numbers, symbols)
3. Update validation messages

### Step 2: Implement Password Breach Checking
1. Add HaveIBeenPwned API integration
2. Check passwords against breach database
3. Prevent use of compromised passwords

### Step 3: Add Progressive Lockout
1. Implement failed attempt tracking
2. Add progressive delays
3. Account lockout after multiple failures

### Step 4: Update Password Reset
1. Apply same rules to password reset
2. Ensure backward compatibility

## Code Changes

### File: `app/Http/Requests/Auth/RegisterRequest.php`
**Before:**
```php
'password' => ['required', 'string', 'min:8', 'confirmed'],
```

**After:**
```php
'password' => [
    'required', 
    'string', 
    'min:12', 
    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
    'confirmed'
],
```

### New File: `app/Services/PasswordSecurityService.php`
```php
<?php

namespace App\Services;

class PasswordSecurityService
{
    public function checkPasswordBreach(string $password): bool
    {
        // Implement HaveIBeenPwned API check
        // Return true if password is compromised
    }
}
```

### File: `app/Http/Controllers/Auth/RegisteredUserController.php`
```php
public function store(RegisterRequest $request)
{
    $passwordSecurity = app(PasswordSecurityService::class);
    
    if ($passwordSecurity->checkPasswordBreach($request->password)) {
        return back()->withErrors([
            'password' => 'This password has been compromised. Please choose a different one.'
        ]);
    }
    
    // Continue with registration...
}
```

## Verification Steps

1. **Password Validation Test:**
   ```bash
   # Test weak password
   curl -X POST /register -d "password=weak"
   ```
   Expected: Validation error

2. **Complexity Test:**
   ```bash
   # Test strong password
   curl -X POST /register -d "password=StrongP@ssw0rd123"
   ```
   Expected: Success

3. **Breach Check Test:**
   - Test with known breached password
   - Verify rejection

## Rollback Plan

If password policy is too strict:
1. Reduce complexity requirements temporarily
2. Implement gradual rollout
3. Allow existing users to keep current passwords

## Testing Requirements

- [ ] Weak passwords rejected
- [ ] Strong passwords accepted
- [ ] Compromised passwords blocked
- [ ] Progressive lockout works
- [ ] Password reset follows same rules

## Documentation Updates

- Update password policy documentation
- Document security improvements
- Mark as completed in master work plan

## Completion Criteria

- [ ] Strong password policy enforced
- [ ] Breach checking active
- [ ] Lockout mechanism working
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #11: Extended Token Lifetimes

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 1-2 hours
**Assigned:** Unassigned

## Problem Description

API tokens valid for 24 hours by default with no invalidation policies, keeping compromised tokens active too long.

**Location:** Sanctum configuration

## Impact Assessment

- **Severity:** Important - Extended attack window
- **Scope:** API authentication
- **Risk:** Low-Medium - Compromised tokens remain valid
- **Dependencies:** Laravel Sanctum, token management

## Solution Overview

Reduce token lifetime and implement proper invalidation policies.

## Implementation Steps

### Step 1: Configure Shorter Token Lifetime
1. Set token expiration to 8 hours
2. Update Sanctum configuration
3. Test token refresh functionality

### Step 2: Implement Token Refresh
1. Add refresh token endpoint
2. Implement sliding expiration
3. Update client-side token handling

### Step 3: Add Concurrent Session Limits
1. Limit active sessions per user
2. Implement session management
3. Add session invalidation on suspicious activity

### Step 4: Token Blacklisting
1. Implement token revocation
2. Add suspicious activity detection
3. Update logout functionality

## Code Changes

### File: `config/sanctum.php`
```php
'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480), // 8 hours in minutes
```

### New File: `app/Http/Controllers/Api/TokenController.php`
```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public function refresh(Request $request)
    {
        $user = $request->user();
        
        // Revoke old token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken('API Token');
        
        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => now()->addMinutes(config('sanctum.expiration')),
        ]);
    }
}
```

### File: `routes/api.php`
```php
Route::post('/token/refresh', [TokenController::class, 'refresh']);
```

## Verification Steps

1. **Token Expiration Test:**
   ```bash
   # Create token and wait 8+ hours
   # Verify token becomes invalid
   ```

2. **Token Refresh Test:**
   ```bash
   curl -X POST /api/token/refresh -H "Authorization: Bearer <old-token>"
   ```
   Expected: New token returned

3. **Session Limit Test:**
   - Create multiple sessions
   - Verify limits enforced

## Rollback Plan

If token changes break clients:
1. Revert to 24-hour expiration
2. Implement gradual migration
3. Update client applications

## Testing Requirements

- [ ] Tokens expire after 8 hours
- [ ] Token refresh works
- [ ] Session limits enforced
- [ ] Suspicious activity detection active

## Documentation Updates

- Update API authentication guide
- Document token lifecycle
- Mark as completed in master work plan

## Completion Criteria

- [ ] Shorter token lifetimes configured
- [ ] Token refresh implemented
- [ ] Session limits working
- [ ] Code reviewed and approved

---

**Estimated Completion:** 1-2 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #12: Potential Information Disclosure in Errors

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 1-2 hours
**Assigned:** Unassigned

## Problem Description

Some error responses may leak internal system information to attackers.

**Location:** Various controllers and middleware

## Impact Assessment

- **Severity:** Important - Information disclosure
- **Scope:** Error handling across application
- **Risk:** Low-Medium - Reconnaissance enablement
- **Dependencies:** Error handling, logging

## Solution Overview

Implement consistent error response format with sanitized messages.

## Implementation Steps

### Step 1: Create Error Response Standard
1. Define standard error response format
2. Create error response helper
3. Implement consistent error codes

### Step 2: Sanitize Error Messages
1. Remove sensitive information from errors
2. Use generic messages in production
3. Implement error message filtering

### Step 3: Update Error Handling
1. Review all controllers for error responses
2. Update middleware error handling
3. Implement exception handlers

### Step 4: Add Security Logging
1. Log full error details internally
2. Exclude sensitive data from responses
3. Monitor for error patterns

## Code Changes

### New File: `app/Exceptions/Handler.php` (Update)
```php
public function render($request, Throwable $exception)
{
    if ($request->is('api/*')) {
        return $this->handleApiException($request, $exception);
    }

    return parent::render($request, $exception);
}

protected function handleApiException($request, Throwable $exception)
{
    // Sanitize error message
    $message = app()->environment('production') 
        ? 'An error occurred' 
        : $exception->getMessage();

    // Log full details internally
    Log::error('API Error', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
        // Exclude sensitive data
    ]);

    return response()->json([
        'error' => [
            'message' => $message,
            'code' => 'INTERNAL_ERROR'
        ]
    ], 500);
}
```

### New File: `app/Services/ErrorResponseService.php`
```php
<?php

namespace App\Services;

class ErrorResponseService
{
    public static function sanitizeMessage(string $message): string
    {
        // Remove file paths, database details, etc.
        $message = preg_replace('/\/var\/www\/[^)]+/', '[REDACTED]', $message);
        $message = preg_replace('/SQLSTATE\[\w+\]/', 'Database error', $message);
        
        return app()->environment('production') ? 'An error occurred' : $message;
    }
}
```

## Verification Steps

1. **Error Response Test:**
   ```bash
   # Trigger an error
   curl "/api/nonexistent-endpoint"
   ```
   Expected: Sanitized error message

2. **Information Leakage Test:**
   - Check error responses don't contain file paths
   - Verify database details not exposed
   - Confirm stack traces hidden in production

3. **Logging Test:**
   - Verify full errors logged internally
   - Check sensitive data not in logs

## Rollback Plan

If error sanitization breaks debugging:
1. Keep detailed errors in development
2. Implement feature flag for error detail level
3. Add admin endpoint for full error details

## Testing Requirements

- [ ] Error messages sanitized
- [ ] No sensitive information leaked
- [ ] Full details logged internally
- [ ] Development debugging preserved

## Documentation Updates

- Update error handling documentation
- Document security logging practices
- Mark as completed in master work plan

## Completion Criteria

- [ ] Error messages sanitized
- [ ] No information disclosure
- [ ] Proper logging implemented
- [ ] Code reviewed and approved

---

**Estimated Completion:** 1-2 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #13: N+1 Query Potential in Middleware

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 30 minutes
**Assigned:** Unassigned

## Problem Description

`EnsureTenantScope` middleware accesses `$user->organization` without eager loading, potentially causing N+1 queries.

**Location:** `app/Http/Middleware/EnsureTenantScope.php:37`

## Impact Assessment

- **Severity:** Normal - Performance issue
- **Scope:** All authenticated requests
- **Risk:** Low - Performance degradation
- **Dependencies:** User authentication, organization scoping

## Solution Overview

Optimize organization access in middleware to prevent N+1 queries.

## Implementation Steps

### Step 1: Analyze Current Implementation
1. Review middleware organization access
2. Check for eager loading opportunities
3. Measure current query performance

### Step 2: Implement Eager Loading
1. Add organization relationship to user queries
2. Update authentication to include organization
3. Verify no additional queries executed

### Step 3: Add Caching (Optional)
1. Cache organization data in middleware
2. Implement organization data caching
3. Monitor cache hit rates

### Step 4: Performance Testing
1. Test with multiple concurrent requests
2. Verify query count reduction
3. Check response time improvements

## Code Changes

### File: `app/Http/Middleware/EnsureTenantScope.php`
**Before:**
```php
if (!$user->organization || !$user->organization->isActive()) {
    // Handle inactive organization
}
```

**After:**
```php
// Assuming organization is eager loaded in authentication
$organization = $user->organization;

if (!$organization || !$organization->isActive()) {
    // Handle inactive organization
}
```

### File: `config/auth.php` or Authentication Logic
```php
// Ensure organization is loaded with user
Auth::user()->load('organization');
```

### Alternative: Cache Implementation
```php
$organization = Cache::remember(
    "user.{$user->id}.organization", 
    3600, // 1 hour
    fn() => $user->organization
);
```

## Verification Steps

1. **Query Count Test:**
   ```bash
   # Use Laravel Debugbar or Telescope
   # Check query count for authenticated requests
   ```

2. **Performance Test:**
   ```bash
   # Load test with authenticated requests
   ab -n 100 -c 10 -H "Authorization: Bearer <token>" /api/test-endpoint
   ```

3. **Cache Test (if implemented):**
   - Verify cache hits
   - Check cache invalidation
   - Monitor memory usage

## Rollback Plan

If optimization causes issues:
1. Revert to lazy loading
2. Remove eager loading from auth
3. Keep caching as optional enhancement

## Testing Requirements

- [ ] No N+1 queries in middleware
- [ ] Performance improved or maintained
- [ ] Organization data accessible
- [ ] Error handling preserved

## Documentation Updates

- Document performance optimization
- Update middleware documentation
- Mark as completed in master work plan

## Completion Criteria

- [ ] N+1 query eliminated
- [ ] Performance verified
- [ ] Organization access working
- [ ] Code reviewed and approved

---

**Estimated Completion:** 30 minutes
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________