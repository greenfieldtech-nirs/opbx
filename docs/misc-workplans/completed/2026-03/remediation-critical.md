# Workplan: Critical Severity Remediation

**Date:** 2026-02-06
**Source:** Full Code Review & Security Audit (2026-02-06)
**Priority:** IMMEDIATE (0-7 days)
**Findings:** 7 Critical issues (CR-1, CR-2, CR-3, SEC-1, SEC-2, SEC-3, SEC-4)
**Status:** ALL TASKS COMPLETED (2026-02-06)

---

## Overview

These 7 findings represent immediate security risks and architectural violations that should be addressed before any new feature work. Each task includes the exact files, lines, and implementation approach.

---

## Task 1: Fix SQL Injection in SessionUpdateController (SEC-1) - COMPLETED

**Commit:** `99b2cf0` - Replaced `DB::raw()` string interpolation with `joinSub()` parameterized query

**File:** `app/Http/Controllers/Api/SessionUpdateController.php:64-80`
**Risk:** SQL injection via interpolated values in `DB::raw()`

### Current Code (Lines 64-80)
```php
$activeCalls = DB::table('session_updates as su1')
    ->select('su1.*')
    ->join(DB::raw('(SELECT session_id, MAX(id) as max_id
                     FROM session_updates
                     WHERE organization_id = '.$organizationId.'
                     AND status IN ("processing", "ringing", "connected", "answer")
                     AND updated_at >= "'.now()->subHours(24)->toDateTimeString().'"
                     GROUP BY session_id) as su2'),
```

### Implementation Plan
1. Replace the raw SQL subquery with an Eloquent subquery using `DB::table()->select()->where()->groupBy()`
2. Use parameterized bindings for `organization_id` and the datetime value
3. Approach:
   ```php
   $subquery = DB::table('session_updates')
       ->select('session_id', DB::raw('MAX(id) as max_id'))
       ->where('organization_id', $organizationId)
       ->whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
       ->where('updated_at', '>=', now()->subHours(24))
       ->groupBy('session_id');

   $activeCalls = DB::table('session_updates as su1')
       ->select('su1.*')
       ->joinSub($subquery, 'su2', function ($join) {
           $join->on('su1.session_id', '=', 'su2.session_id')
               ->on('su1.id', '=', 'su2.max_id');
       })
       ->where('su1.organization_id', $organizationId)
       ->whereNotIn('su1.session_id', $completedSessionIds)
       ->whereNotIn('su1.action', ['deleted', 'cdr_final_status'])
       ->orderBy('su1.updated_at', 'desc')
       ->get();
   ```
4. Verify existing tests pass after change
5. Add a dedicated test for this query with various organization IDs

### Testing
- Run existing `SessionUpdateController` tests
- Add test: verify query returns correct results for a specific org
- Add test: verify no cross-tenant data leakage

### Estimated Changes
- 1 file modified: `app/Http/Controllers/Api/SessionUpdateController.php`
- 1 file added: test for parameterized query (if not already covered)

---

## Task 2: Fix Wildcard Token Abilities on Registration (SEC-2) - COMPLETED

**Commit:** `ec0c45c` - Replaced `['*']` with explicit scoped owner abilities constant

**File:** `app/Http/Controllers/Api/RegisterController.php:44-48`
**Risk:** Registration token bypasses all RBAC token ability scoping

### Current Code (Lines 44-48)
```php
$token = $user->createToken(
    'registration-token',
    ['*'],
    now()->addDay()
)->plainTextToken;
```

### Implementation Plan
1. The `AuthController` already defines proper scoped abilities in `TOKEN_ABILITIES` constant (lines 49-99)
2. Extract `getTokenAbilities()` method from `AuthController` into a shared trait or service
3. Use the owner-scoped abilities for the registration token instead of `['*']`
4. Approach:
   - Option A (minimal): Copy the owner abilities array directly into RegisterController
   - Option B (recommended): Create a `TokenAbilityService` or a `HasTokenAbilities` trait shared between `AuthController` and `RegisterController`
   - The trait approach is preferred since `AuthController` already has `TOKEN_ABILITIES` and `getTokenAbilities()` defined
5. Steps:
   a. Create trait `App\Http\Controllers\Traits\HasTokenAbilities` containing the `TOKEN_ABILITIES` constant and `getTokenAbilities()` method
   b. Update `AuthController` to use the trait (remove the constant and method from the controller body)
   c. Update `RegisterController` to use the trait
   d. Replace `['*']` with `$this->getTokenAbilities(UserRole::OWNER)`

### Testing
- Update `RegisterControllerTest` (if exists) to verify the returned token has scoped abilities
- Verify login flow still works correctly after refactoring `AuthController`
- Test that the registration token can perform owner-level operations but not wildcard operations

### Estimated Changes
- 1 file added: `app/Http/Controllers/Traits/HasTokenAbilities.php`
- 2 files modified: `AuthController.php`, `RegisterController.php`

---

## Task 3: Secure Recording File Access (SEC-3) - COMPLETED

**Commit:** `3e4c74d` - Added HMAC-signed URL verification, removed wildcard CORS, removed duplicate route

**File:** `app/Http/Controllers/Api/RecordingsController.php:393-501`
**Routes:** `routes/api.php:48-50`, `routes/web.php:10-12`
**Risk:** Any user can access any recording file without authentication by guessing `{org_id}/{filename}`

### Current Code
```php
// routes/api.php line 48 - PUBLIC, NO AUTH
Route::get('storage/recordings/{path}', [RecordingsController::class, 'serveMinioFile'])
    ->where('path', '[0-9]+/.+');

// routes/web.php line 10 - ALSO PUBLIC, NO AUTH
Route::get('storage/recordings/{path}', [RecordingsController::class, 'serveMinioFile'])
    ->where('path', '[0-9]+/.+');
```

### Implementation Plan

This endpoint serves two use cases that require different solutions:
- **Cloudonix IVR playback:** Cloudonix needs to fetch audio files via HTTP URL during call routing. It cannot use bearer tokens or session cookies.
- **Browser playback/download:** Authenticated users playing recordings in the UI.

**Approach:**

1. **For Cloudonix access (IVR audio):** Implement HMAC-signed URLs with expiration
   a. Create a `SignedUrlService` with `generate(filePath, orgId, expiresAt)` and `verify(signature, filePath, orgId, expiresAt)` methods
   b. The signing key should be the organization's `domain_requests_api_key` or a dedicated signing secret
   c. URL format: `/api/v1/storage/recordings/{path}?expires={timestamp}&signature={hmac}`
   d. Reject requests with expired timestamps or invalid signatures
   e. Update the IVR CXML builder to generate signed URLs when referencing audio files

2. **For browser access:** Require `auth:sanctum` middleware
   a. Create a separate authenticated route: `/api/v1/recordings/{id}/stream`
   b. Verify organization_id matches the authenticated user's organization
   c. Proxy the file from MinIO with proper Content-Type headers

3. **Remove the duplicate route** from `routes/web.php` (line 10-12)

4. **Remove `Access-Control-Allow-Origin: *`** from the response headers (line 487 in RecordingsController)

5. **Update `Recording::getPlaybackUrl()`** and `Recording::getDownloadUrl()` methods to generate signed URLs

### Testing
- Test: signed URL with valid signature returns file
- Test: expired signed URL returns 403
- Test: tampered signature returns 403
- Test: authenticated route requires valid session/token
- Test: cross-tenant access is blocked

### Estimated Changes
- 1 file added: `app/Services/Recording/SignedUrlService.php`
- 3 files modified: `RecordingsController.php`, `routes/api.php`, `routes/web.php`
- 1 file modified: `app/Models/Recording.php` (update URL generation)
- Files referencing playback URLs in CXML builders may need updates

---

## Task 4: Rotate Secrets & Verify Git History (SEC-4) - COMPLETED

**Result:** Verified `.env` was never committed to git history. Secret rotation deferred to operator.

**File:** `.env`
**Risk:** Active secrets present in working directory; possible git history exposure

### Implementation Plan

This is an operational task, not a code change:

1. **Verify git history:**
   ```bash
   git log --all --diff-filter=A -- .env
   git log --all -- .env
   ```
   If `.env` was ever committed, the secrets are permanently in git history.

2. **Rotate the following secrets:**
   - `APP_KEY` - Run `php artisan key:generate` on all environments
   - `NGROK_AUTHTOKEN` - Generate new token from ngrok dashboard
   - `CLOUDONIX_DOMAIN_CDR_AUTH_KEY` - Rotate via Cloudonix portal
   - `PUSHER_APP_SECRET` - Update Soketi configuration
   - `REDIS_PASSWORD` - Update Redis server and all clients
   - `DB_PASSWORD` / `DB_ROOT_PASSWORD` - Update MySQL and all clients
   - `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` - Update MinIO and all clients

3. **If secrets were in git history:**
   - Use `git filter-repo` or BFG Repo Cleaner to remove `.env` from history
   - Force-push cleaned history (coordinate with team)
   - Treat ALL secrets as compromised regardless

4. **Add deployment validation:**
   - Add a check in `docker/php/entrypoint.sh` that verifies critical env vars are not set to default/known values
   - Example: reject startup if `DB_PASSWORD=secret` or `MINIO_SECRET_KEY=minioadmin`

### Estimated Changes
- 0 code files (operational task)
- 1 file potentially modified: `docker/php/entrypoint.sh` (startup validation)

---

## Task 5: Implement Webhook Idempotency in callInitiated (CR-1) - COMPLETED

**Commit:** `813c1c9` - Fixed middleware key generation (route name fallback), removed timestamp from key, added Redis lock + CXML caching in controller

**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:39-50`
**Risk:** Duplicate webhooks cause double call processing; CLAUDE.md requirement violation

### Current Code (Lines 39-50)
```php
public function callInitiated(CallInitiatedRequest $request): Response
{
    $callId = $request->input('CallSid') ?? $request->input('call_id');
    $from = $request->input('From') ?? $request->input('from');
    $to = $request->input('To') ?? $request->input('to');
    // Proceeds directly to routing - no idempotency check
```

### Implementation Plan

Note: The `EnsureWebhookIdempotency` middleware already exists but has gaps (SEC-15). This task focuses on making `callInitiated` properly idempotent.

1. **Verify middleware is applied to the route:**
   - Check `routes/webhooks.php` to confirm `EnsureWebhookIdempotency` middleware is applied to `call-initiated` route
   - If not, add it

2. **Add response caching to the handler:**
   a. After routing logic generates a CXML response, cache it keyed by `CallSid`
   b. TTL: 1 hour (calls shouldn't last longer)
   c. Key format: `idem:call-initiated:{CallSid}`
   d. On subsequent requests with the same `CallSid`, return the cached CXML response

3. **Add Redis lock around processing:**
   a. Before processing, acquire lock: `lock:call-initiated:{CallSid}` with 30s timeout
   b. If lock already held, wait briefly (3s), then return cached response or error CXML
   c. This prevents two concurrent webhook deliveries from racing

4. **Implementation approach:**
   ```php
   public function callInitiated(CallInitiatedRequest $request): Response
   {
       $callId = $request->input('CallSid') ?? $request->input('call_id');
       $cacheKey = "idem:call-initiated:{$callId}";

       // Check for cached response (idempotency)
       $cachedResponse = Cache::get($cacheKey);
       if ($cachedResponse) {
           Log::info('Returning cached response for duplicate webhook', ['call_id' => $callId]);
           return response($cachedResponse, 200)->header('Content-Type', 'application/xml');
       }

       // Acquire lock to prevent concurrent processing
       $lock = Cache::lock("lock:call-initiated:{$callId}", 30);
       if (!$lock->get()) {
           // Another request is processing this call - wait and return cached
           // ...
       }

       try {
           // ... existing routing logic ...

           // Cache the response
           Cache::put($cacheKey, $cxmlResponse, 3600);

           return response($cxmlResponse, 200)->header('Content-Type', 'application/xml');
       } finally {
           $lock->release();
       }
   }
   ```

### Testing
- Test: first webhook call processes normally and returns CXML
- Test: second call with same CallSid returns cached CXML without re-processing
- Test: concurrent calls with same CallSid are serialized via lock
- Test: different CallSid values are processed independently

### Estimated Changes
- 1 file modified: `CloudonixWebhookController.php`
- 1 file potentially modified: `routes/webhooks.php` (middleware verification)
- Test files added/updated

---

## Task 6: Secure Password Endpoints (CR-2) - COMPLETED

**Commit:** `ff01257` - Added no-cache headers, registered and fixed sensitive-operations rate limiter, applied to getPassword route

**File:** `app/Http/Controllers/Api/ExtensionController.php:692, 768`
**Risk:** Plaintext SIP passwords in JSON responses can be captured by intermediaries

### Implementation Plan

1. **Add security headers to password responses:**
   - Add `Cache-Control: no-store, no-cache, must-revalidate` header
   - Add `Pragma: no-cache` header
   - Add `X-Content-Type-Options: nosniff` header
   - These prevent browsers and proxies from caching the response

2. **Limit password visibility window:**
   - `getPassword` endpoint: Consider removing entirely or requiring re-authentication
   - `resetPassword` endpoint: Return password only on this one-time operation (acceptable since the user needs to configure their SIP client)

3. **Add rate limiting to getPassword:**
   - Apply `sensitive-operations` middleware to the `GET password` route in `routes/api.php:207-208`
   - This addresses CR-14 simultaneously

4. **Add audit logging:**
   - Log every `getPassword` and `resetPassword` call with user ID, IP, and extension ID
   - Use the existing `AuditLogger` service

5. **Implementation for response headers:**
   ```php
   return response()->json([
       'message' => 'Extension password reset successfully.',
       'new_password' => $newPassword,
       'extension' => new ExtensionResource($extension),
   ])->withHeaders([
       'Cache-Control' => 'no-store, no-cache, must-revalidate',
       'Pragma' => 'no-cache',
   ]);
   ```

### Testing
- Test: response headers include no-cache directives
- Test: rate limiting is enforced on getPassword
- Test: audit log entries are created for password access

### Estimated Changes
- 1 file modified: `ExtensionController.php` (add headers + audit logging)
- 1 file modified: `routes/api.php` (add middleware to getPassword route)

---

## Task 7: Fix ExtensionController Transaction Pattern (CR-3) - COMPLETED

**Result:** Already resolved - `ExtensionCrudController` extends `AbstractApiCrudController` with proper transaction wrapping and hooks. Old `ExtensionController` is dead code (no route references).

**File:** `app/Http/Controllers/Api/ExtensionController.php:219-231`
**Risk:** Bypasses AbstractApiCrudController safety mechanisms; inconsistent patterns

### Implementation Plan

This task is closely related to CR-4 (Fat Controller refactoring, in the High workplan). The minimal fix here is to align the transaction pattern while deferring the full refactor.

1. **If ExtensionController already extends AbstractApiCrudController:**
   - Override `shouldUseTransactionForStore()` to return `true`
   - Move creation logic into `beforeStore()` / `afterStore()` hooks
   - Remove manual `DB::transaction()` calls

2. **If ExtensionController does NOT extend AbstractApiCrudController:**
   - This is the current situation (it extends `Controller` directly)
   - Option A: Migrate to extend `AbstractApiCrudController` (larger change, deferred to CR-4)
   - Option B (minimal): Ensure the manual `DB::transaction()` calls include all related operations (Cloudonix sync, etc.) within the transaction, and add proper rollback handling

3. **For the minimal fix (Option B):**
   - Ensure the transaction wraps all database writes including related model updates
   - Add explicit rollback handling for Cloudonix API failures
   - Add try/catch within the transaction closure to handle partial failures

### Testing
- Test: extension creation within transaction rolls back on failure
- Test: related model updates are included in the transaction

### Estimated Changes
- 1 file modified: `ExtensionController.php`

---

## Execution Order

Tasks should be executed in this order due to dependencies:

1. **Task 4** (Rotate Secrets) - Operational, can start immediately
2. **Task 1** (SQL Injection) - Standalone fix, highest security risk
3. **Task 2** (Wildcard Token) - Standalone fix, auth bypass
4. **Task 3** (Recording Access) - Larger change, may affect IVR audio playback
5. **Task 5** (Webhook Idempotency) - Requires understanding of webhook flow
6. **Task 6** (Password Security) - Quick fix with headers + rate limiting
7. **Task 7** (Transaction Pattern) - Smallest impact, can be last

### Validation After All Tasks
- Run full test suite: `php artisan test`
- Verify webhook flow end-to-end with test Cloudonix call
- Verify IVR audio playback still works after recording URL changes
- Verify registration + login flow after token ability changes
- Verify Live Calls page still loads after SQL query refactor
