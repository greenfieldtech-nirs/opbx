# Workplan: Low Severity Remediation

**Date:** 2026-02-06
**Source:** Full Code Review & Security Audit (2026-02-06)
**Priority:** LONG-TERM (90+ days / as-needed)
**Findings:** 10 Low issues (CR-29 through CR-32, SEC-21 through SEC-26)
**Depends on:** No hard dependencies

---

## Overview

These 10 findings are minor improvements, defense-in-depth measures, and polish items. They carry minimal immediate risk but improve overall code quality and security posture over time. Several can be addressed opportunistically when working on related code.

---

## Task 1: Remove User Existence Leak from Login Logs (SEC-21)

**File:** `app/Http/Controllers/Api/AuthController.php:135-139`
**Risk:** Log readers can distinguish between "user not found" and "wrong password" failures

### Current Code
```php
Log::warning('Login failed - invalid credentials', [
    'user_exists' => $user !== null,
]);
```

### Plan
1. Remove `user_exists` field from the log entry
2. Keep the log entry itself (it's useful for monitoring failed login attempts)
3. The API response already correctly returns a generic "Invalid credentials" message

### Files Changed
- `app/Http/Controllers/Api/AuthController.php` (remove 1 field from log array)

---

## Task 2: Add CDR Replay Window Limit (SEC-22)

**File:** `app/Http/Middleware/EnsureWebhookIdempotency.php:43-58`
**Risk:** CDR webhooks accepted with arbitrarily old timestamps, enabling unlimited replay

### Current Code
CDR webhooks bypass timestamp-based replay protection entirely.

### Plan
1. Replace the unlimited acceptance with a generous but finite window:
   ```php
   if ($isCdrWebhook) {
       $maxAge = 86400; // 24 hours
       if ($timestamp < now()->subSeconds($maxAge)->timestamp) {
           Log::warning('CDR webhook rejected - too old', [
               'timestamp' => $timestamp,
               'max_age_seconds' => $maxAge,
           ]);
           return response()->json(['error' => 'CDR too old'], 422);
       }
       // Accept CDRs within the 24-hour window
   }
   ```
2. Make the window configurable via `config/webhooks.php`:
   ```php
   'cdr_max_age_seconds' => env('WEBHOOK_CDR_MAX_AGE', 86400),
   ```
3. Log accepted old CDRs at INFO level for monitoring

### Files Changed
- `app/Http/Middleware/EnsureWebhookIdempotency.php`
- `config/webhooks.php`

---

## Task 3: Fix CallStateManager Lock Ownership (SEC-23)

**File:** `app/Services/CallStateManager/CallStateManager.php:74-84`
**Risk:** `forceRelease()` releases locks regardless of ownership

### Current Code
```php
public function releaseLock(string $callId): void
{
    $lockKey = $this->getLockKey($callId);
    Cache::lock($lockKey)->forceRelease();
}
```

### Plan
1. Store the lock owner token when acquiring:
   ```php
   public function acquireLock(string $callId, int $timeout = 30): ?string
   {
       $lockKey = $this->getLockKey($callId);
       $lock = Cache::lock($lockKey, $timeout);
       $owner = Str::random(20);

       if ($lock->get(function () use ($owner) { return $owner; })) {
           return $owner;
       }
       return null;
   }
   ```
2. Release only owned locks:
   ```php
   public function releaseLock(string $callId, string $owner): void
   {
       $lockKey = $this->getLockKey($callId);
       Cache::restoreLock($lockKey, $owner)->release();
   }
   ```
3. Update all callers to pass the owner token through
4. Alternative simpler approach: use Laravel's lock within a closure, which handles ownership automatically:
   ```php
   Cache::lock($lockKey, $timeout)->block(5, function () {
       // ... process call state ...
   });
   ```

### Files Changed
- `app/Services/CallStateManager/CallStateManager.php`
- Callers of `acquireLock` / `releaseLock`

---

## Task 4: Make Circuit Breaker Counter Atomic (SEC-24)

**File:** `app/Services/CircuitBreaker/CircuitBreaker.php:142-148`
**Risk:** Non-atomic read-then-write under concurrency causes under-counting

### Current Code
```php
private function recordFailure(): void
{
    $key = $this->getFailuresKey();
    $failures = Cache::get($key, 0) + 1;
    Cache::put($key, $failures, ...);
}
```

### Plan
1. Replace with atomic increment:
   ```php
   private function recordFailure(): void
   {
       $key = $this->getFailuresKey();
       $ttl = $this->config['failure_window'] ?? 60;

       if (!Cache::has($key)) {
           Cache::put($key, 0, $ttl);
       }
       Cache::increment($key);
   }
   ```
2. Similarly for `recordSuccess`, if it decrements a counter
3. `Cache::increment()` uses Redis `INCR` which is atomic

### Files Changed
- `app/Services/CircuitBreaker/CircuitBreaker.php`

---

## Task 5: Sanitize Exception Messages in API Responses (SEC-25)

**Files:** `app/Http/Controllers/Api/RecordingsController.php:155-158`, `app/Http/Controllers/Api/SessionUpdateController.php:530`
**Risk:** Internal exception details exposed to API clients

### Current Code
```php
return response()->json([
    'error' => 'Failed to create recording',
    'message' => $e->getMessage(),
], 500);
```

### Plan
1. Replace `$e->getMessage()` with generic messages in all API responses:
   ```php
   return response()->json([
       'error' => 'Failed to create recording',
       'message' => 'An internal error occurred. Please try again.',
   ], 500);
   ```
2. Ensure the full exception is logged:
   ```php
   Log::error('Failed to create recording', [
       'exception' => $e->getMessage(),
       'trace' => $e->getTraceAsString(),
   ]);
   ```
3. Grep for `$e->getMessage()` in response bodies across all controllers
4. In development mode (`APP_DEBUG=true`), it's acceptable to include the message - use:
   ```php
   'message' => config('app.debug') ? $e->getMessage() : 'An internal error occurred.',
   ```

### Files Changed
- `app/Http/Controllers/Api/RecordingsController.php`
- `app/Http/Controllers/Api/SessionUpdateController.php`
- Any other controllers returning `$e->getMessage()` to clients

---

## Task 6: Make CORS localhost Origins Environment-Conditional (SEC-26)

**File:** `config/cors.php:24-30`
**Risk:** Localhost origins in production could enable local browser-based attacks

### Plan
Note: This is also listed in the Medium workplan (Group F, Task F1). If already addressed there, skip.

1. Use `APP_ENV` to conditionally include localhost origins:
   ```php
   'allowed_origins' => array_filter([
       env('FRONTEND_URL'),
       env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
       env('APP_ENV') === 'local' ? 'http://127.0.0.1:3000' : null,
       env('APP_ENV') === 'local' ? 'http://localhost' : null,
       env('APP_ENV') === 'local' ? 'http://127.0.0.1' : null,
   ]),
   ```

### Files Changed
- `config/cors.php`

---

## Task 7: Make "Remember Me" Configurable on Login (SEC-26b - from audit finding 6.3)

**File:** `app/Http/Controllers/Api/AuthController.php:206`
**Risk:** Sessions persist beyond browser close by default

### Current Code
```php
Auth::guard('web')->login($user, true);  // Always "remember me"
```

### Plan
1. Accept a `remember_me` parameter from the login request:
   ```php
   $remember = $request->boolean('remember_me', false);
   Auth::guard('web')->login($user, $remember);
   ```
2. Add validation rule in `LoginRequest`:
   ```php
   'remember_me' => ['nullable', 'boolean'],
   ```
3. Update frontend login form to include a "Remember me" checkbox (optional - could be deferred)

### Files Changed
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `frontend/src/pages/Login.tsx` (optional)

---

## Task 8: Fix Webhook Idempotency for Keyless Webhooks (SEC-15 supplement)

**File:** `app/Http/Middleware/EnsureWebhookIdempotency.php:27-33`
**Risk:** Webhooks without identifiable keys bypass deduplication

### Plan
1. Instead of allowing keyless webhooks through, generate a deterministic key from the payload:
   ```php
   if (!$idempotencyKey) {
       // Generate key from payload hash + source IP
       $payloadHash = hash('sha256', $request->getContent() . $request->ip());
       $idempotencyKey = "webhook:auto:{$payloadHash}";
       Log::info('Generated automatic idempotency key for keyless webhook', [
           'key' => $idempotencyKey,
       ]);
   }
   ```
2. This ensures even keyless webhooks are deduplicated if the exact same payload arrives from the same IP
3. TTL for auto-generated keys can be shorter (5 minutes) since these are likely retries

### Files Changed
- `app/Http/Middleware/EnsureWebhookIdempotency.php`

---

## Task 9: Clean Up Unused Imports (CR-32 area)

**Risk:** Code cleanliness

### Plan
1. Run PHP-CS-Fixer with import ordering rules:
   ```bash
   ./vendor/bin/php-cs-fixer fix --rules='{"no_unused_imports": true, "ordered_imports": {"sort_algorithm": "alpha"}}' app/
   ```
2. Alternatively, add this to CI pipeline as a check
3. For frontend, run ESLint with `no-unused-imports` rule

### Files Changed
- Multiple files (automated fix)

---

## Task 10: Add TypeScript Strict Mode Audit (CR-32 area)

**Risk:** Type safety gaps in frontend

### Plan
1. Check current `tsconfig.json` strict mode settings
2. Run TypeScript compiler with `--strict` flag to find violations:
   ```bash
   cd frontend && npx tsc --noEmit --strict 2>&1 | head -100
   ```
3. Prioritize fixing `any` usage in:
   - API service types
   - Component props
   - Event handlers
4. This is an ongoing improvement, not a single task. Track progress by counting `any` occurrences:
   ```bash
   grep -r ": any" frontend/src/ --include="*.ts" --include="*.tsx" | wc -l
   ```

### Files Changed
- `frontend/tsconfig.json` (potentially)
- Frontend TypeScript files with `any` usage

---

## Execution Notes

These tasks have no hard dependencies and can be done opportunistically:

- **When touching a file for other work:** Address the Low finding in that file at the same time
- **During code review:** Flag Low findings as "while you're here" improvements
- **Sprint padding:** Use these tasks to fill remaining capacity in sprints
- **New contributor onboarding:** Good first-contribution tasks

### Quick Wins (< 15 minutes each)
1. Task 1 (remove log field) - 5 min
2. Task 4 (atomic counter) - 10 min
3. Task 6 (CORS) - 10 min (if not already done in Medium workplan)

### Moderate Effort (30-60 minutes each)
4. Task 2 (CDR replay window) - 30 min
5. Task 5 (exception messages) - 30 min
6. Task 7 (remember me) - 30 min
7. Task 8 (keyless webhook idempotency) - 30 min

### Larger Effort (1-2 hours each)
8. Task 3 (lock ownership) - 1-2 hours (requires updating callers)
9. Task 9 (unused imports) - 30 min (automated)
10. Task 10 (TypeScript strict) - ongoing
