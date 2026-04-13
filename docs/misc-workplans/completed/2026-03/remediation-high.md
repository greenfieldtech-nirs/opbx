# Workplan: High Severity Remediation

**Date:** 2026-02-06
**Source:** Full Code Review & Security Audit (2026-02-06)
**Priority:** SHORT-TERM (7-30 days)
**Findings:** 19 High issues (CR-4 through CR-15, SEC-5 through SEC-11)
**Depends on:** Critical workplan completion

---

## Overview

These 19 findings represent significant code quality and security issues that should be addressed in the sprint following critical fixes. Many are quick targeted changes; a few (CR-4, CR-11) are larger refactors.

---

## Task 1: Remove X-Organization-ID Header Fallback (SEC-5)

**File:** `app/Http/Middleware/RateLimitPerOrganization.php:128-133`
**Risk:** Attackers can spoof organization ID to bypass rate limits or cause DoS for another org

### Plan
1. Remove the `X-Organization-ID` header fallback block (lines 128-133)
2. If needed for testing, gate it behind environment check:
   ```php
   if (app()->environment('local', 'testing')) {
       $headerOrgId = $request->header('X-Organization-ID');
       if ($headerOrgId) {
           return (int) $headerOrgId;
       }
   }
   ```
3. Update any tests that rely on this header to use proper auth instead

### Files Changed
- `app/Http/Middleware/RateLimitPerOrganization.php`
- Possibly test files that use the header

---

## Task 2: Mask API Keys in Settings Response (SEC-6)

**File:** `app/Http/Controllers/Api/SettingsController.php:73-74`
**Risk:** Decrypted Cloudonix API keys sent as plaintext in JSON responses

### Plan
1. In the `show()` method, replace direct key access with masked versions:
   ```php
   'domain_api_key' => $settings->getMaskedDomainApiKey(),
   'domain_requests_api_key' => $settings->getMaskedDomainRequestsApiKey(),
   ```
2. Verify `getMaskedDomainApiKey()` and `getMaskedDomainRequestsApiKey()` exist on `CloudonixSettings` model (they should based on audit findings)
3. If these methods don't exist, create them:
   ```php
   public function getMaskedDomainApiKey(): ?string
   {
       $key = $this->domain_api_key;
       if (!$key || strlen($key) < 8) return $key ? '****' : null;
       return substr($key, 0, 4) . str_repeat('*', strlen($key) - 8) . substr($key, -4);
   }
   ```
4. Create a separate `POST /api/v1/settings/reveal-key` endpoint (owner-only, rate-limited, audit-logged) that returns the full key on explicit request
5. Update the frontend Settings page to show masked keys with a "Reveal" button that calls the new endpoint

### Files Changed
- `app/Http/Controllers/Api/SettingsController.php`
- `app/Models/CloudonixSettings.php` (if masking methods don't exist)
- `routes/api.php` (new reveal endpoint)
- `frontend/src/pages/Settings.tsx` (reveal button)

---

## Task 3: Strengthen CDR Webhook Authentication (SEC-7)

**File:** `app/Http/Middleware/VerifyCloudonixSignature.php:62-113`
**Risk:** CDR webhooks authenticated only by domain UUID from payload - forgeable

### Plan
1. Check what authentication mechanisms Cloudonix supports for CDR webhooks (consult docs)
2. **Option A (preferred):** If Cloudonix sends an HMAC signature header for CDRs, verify it using the existing HMAC verification path in the middleware
3. **Option B:** If Cloudonix doesn't support CDR signatures, implement IP-based allow-listing:
   a. Add `CLOUDONIX_ALLOWED_IPS` to `.env.example`
   b. Add IP verification in the CDR authentication path
   c. Maintain a list of Cloudonix source IPs
4. **Option C (minimal):** Add the CDR auth key (`CLOUDONIX_DOMAIN_CDR_AUTH_KEY` from `.env`) verification as a Bearer token check in addition to the domain UUID lookup:
   ```php
   private function handleCdrAuthentication(Request $request, Closure $next): Response
   {
       // Verify CDR auth key header
       $authKey = $request->header('X-CDR-Auth-Key') ?? $request->bearerToken();
       $expectedKey = config('webhooks.cdr_auth_key');
       if ($expectedKey && !hash_equals($expectedKey, $authKey ?? '')) {
           return response()->json(['error' => 'Unauthorized'], 401);
       }

       // Then proceed with domain UUID lookup for tenant identification
       // ...
   }
   ```
5. Use `hash_equals()` for constant-time comparison to prevent timing attacks

### Files Changed
- `app/Http/Middleware/VerifyCloudonixSignature.php`
- `config/webhooks.php` (add CDR auth key config)
- `.env.example` (document CDR auth key)

---

## Task 4: Add Tenant Check to RecordingsController (SEC-8)

**File:** `app/Http/Controllers/Api/RecordingsController.php:164-178`
**Risk:** Route model binding may bypass organization scope

### Plan
1. Add explicit tenant verification in `show()`, `update()`, `delete()` methods:
   ```php
   public function show(Request $request, Recording $recording): JsonResponse
   {
       $user = $this->getAuthenticatedUser();
       if ($recording->organization_id !== $user->organization_id) {
           abort(404);
       }
       // ...
   }
   ```
2. Better yet, scope the query explicitly:
   ```php
   $recording = Recording::where('id', $recording->id)
       ->where('organization_id', $user->organization_id)
       ->firstOrFail();
   ```
3. Review all other controllers that use route model binding without `AbstractApiCrudController` for the same issue
4. Consider migrating `RecordingsController` to extend `AbstractApiCrudController` which handles this automatically

### Files Changed
- `app/Http/Controllers/Api/RecordingsController.php`

---

## Task 5: Secure Docker Port Exposure (SEC-9)

**File:** `docker-compose.yml:137-138`
**Risk:** MySQL, MinIO ports exposed to host network

### Plan
1. Use environment variable pattern for conditional port exposure (like Redis already does):
   ```yaml
   mysql:
     ports:
       - "${DB_EXPOSE_PORT:+${DB_EXPOSE_PORT}:}3306"
   ```
   When `DB_EXPOSE_PORT` is unset, no port is exposed. When set (e.g., `3306`), it maps.
2. Apply the same pattern to MinIO ports (9000, 9001)
3. Update `.env.example` with comments explaining port exposure is for development only:
   ```
   # Set to expose database port to host (development only, leave empty for production)
   DB_EXPOSE_PORT=3306
   MINIO_EXPOSE_PORT=9000
   MINIO_CONSOLE_EXPOSE_PORT=9001
   ```
4. Add a comment in `docker-compose.yml` warning about production exposure

### Files Changed
- `docker-compose.yml`
- `.env.example`

---

## Task 6: Remove Default Credentials from Docker Compose (SEC-10)

**File:** `docker-compose.yml:61-65, 140-141, 186-187`
**Risk:** Services start with well-known passwords if .env is missing

### Plan
1. Remove default fallback values for all passwords:
   ```yaml
   # Before:
   - DB_PASSWORD=${DB_PASSWORD:-secret}
   # After:
   - DB_PASSWORD=${DB_PASSWORD:?DB_PASSWORD must be set in .env}
   ```
2. Apply to all credential fallbacks:
   - `DB_PASSWORD` (line 61)
   - `DB_ROOT_PASSWORD` (line 65)
   - `MYSQL_ROOT_PASSWORD` (line 140-141)
   - `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` (line 186-187)
   - `REDIS_PASSWORD` if it has a default
3. Add validation in `docker/php/entrypoint.sh`:
   ```bash
   # Reject known-weak passwords
   if [ "$DB_PASSWORD" = "secret" ] || [ "$DB_PASSWORD" = "password" ]; then
       echo "ERROR: DB_PASSWORD is set to a default value. Please use a strong password."
       exit 1
   fi
   ```
4. Update `.env.example` with strong placeholder instructions

### Files Changed
- `docker-compose.yml`
- `docker/php/entrypoint.sh`
- `.env.example`

---

## Task 7: Fix Admin Rate Limit Bypass (SEC-11)

**File:** `app/Http/Middleware/RateLimitSensitiveOperations.php:27-29`
**Risk:** Compromised admin account has zero throttling on sensitive operations

### Plan
1. Replace the admin bypass with elevated limits:
   ```php
   public function handle(Request $request, Closure $next): Response
   {
       $maxAttempts = $request->user()?->isAdmin()
           ? (int) config('rate_limiting.sensitive_admin', 60)
           : (int) config('rate_limiting.sensitive', 10);

       // Apply rate limiting with role-appropriate limit
       // ...
   }
   ```
2. Add `sensitive_admin` key to `config/rate_limiting.php`
3. Add `RATE_LIMIT_SENSITIVE_ADMIN=60` to `.env.example`

### Files Changed
- `app/Http/Middleware/RateLimitSensitiveOperations.php`
- `config/rate_limiting.php`
- `.env.example`

---

## Task 8: Refactor ExtensionController (CR-4)

**File:** `app/Http/Controllers/Api/ExtensionController.php` (896 lines)
**Risk:** SRP violation, maintenance burden, inconsistent patterns

### Plan

This is the largest task in this workplan. The controller currently handles:
- CRUD operations (index, show, store, update, destroy)
- Password management (getPassword, resetPassword)
- Cloudonix synchronization (syncToCloudonix)
- Type-specific handling (user, AI assistant, conference, queue, group)

**Step 1:** Migrate CRUD to `AbstractApiCrudController`
- Make `ExtensionController` extend `AbstractApiCrudController`
- Override hooks: `beforeStore()`, `afterStore()`, `beforeUpdate()`, `afterUpdate()`, `beforeDestroy()`
- Move type-specific logic into the hooks
- Remove manual `DB::transaction()` calls (also fixes CR-3)

**Step 2:** Move password operations to `ExtensionPasswordController`
- This controller already exists at `app/Http/Controllers/Api/ExtensionPasswordController.php`
- Verify it has `getPassword()` and `resetPassword()` methods
- Update routes in `routes/api.php` to point to `ExtensionPasswordController`
- Remove password methods from `ExtensionController`

**Step 3:** Move Cloudonix sync to a service
- Create `App\Services\Cloudonix\ExtensionSyncService` (or use existing CloudonixSubscriberService)
- Move `syncToCloudonix()` logic from the controller into the service
- Controller calls the service; service handles all Cloudonix API interaction

**Target:** ExtensionController should be under 300 lines, comparable to `RingGroupController`

### Files Changed
- `app/Http/Controllers/Api/ExtensionController.php` (major refactor)
- `app/Http/Controllers/Api/ExtensionPasswordController.php` (verify/update)
- `app/Services/Cloudonix/ExtensionSyncService.php` (new or update existing)
- `routes/api.php` (route updates)

---

## Task 9: Fix N+1 Queries in VoiceRoutingManager (CR-5)

**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php:401, 551-609`
**Risk:** Performance degradation during peak call volume

### Plan
1. **Add composite index** for the hot query path:
   ```bash
   php artisan make:migration add_extension_routing_index_to_extensions_table
   ```
   ```php
   $table->index(['organization_id', 'extension_number', 'status']);
   ```
   (This also addresses CR-12)

2. **Pre-load relationships in VoiceRoutingCacheService:**
   - When caching business hours schedules, eager load related extensions
   - When caching DID routing config, include extension relationships
   - Use `->with(['extension', 'extension.user', 'extension.aiAssistant'])` in cache warm-up queries

3. **Batch extension lookups:**
   - In business hours routing, collect all extension IDs needed first
   - Execute a single query with `whereIn('id', $extensionIds)` instead of individual queries in a loop
   - Map results by ID for O(1) lookup

### Files Changed
- 1 migration file added
- `app/Services/VoiceRouting/VoiceRoutingManager.php`
- `app/Services/VoiceRouting/VoiceRoutingCacheService.php`

---

## Task 10: Reduce Logging Verbosity (CR-6)

**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php` (50+ Log::info statements)
**Risk:** Log noise, performance impact, harder debugging

### Plan
1. Audit all `Log::info()` calls in `VoiceRoutingManager.php`
2. Downgrade routine/diagnostic logs to `Log::debug()`:
   - Extension lookups
   - Cache hits/misses
   - Intermediate routing decisions
   - Parameter logging
3. Keep `Log::info()` only for:
   - Final routing decision (which extension/ring group was chosen)
   - Errors and fallback activations
   - Call state transitions
4. Keep `Log::warning()` and `Log::error()` as-is
5. Apply same principle to `VoiceRoutingCacheService` and routing strategies

### Files Changed
- `app/Services/VoiceRouting/VoiceRoutingManager.php`
- `app/Services/VoiceRouting/VoiceRoutingCacheService.php`
- `app/Services/VoiceRouting/Strategies/*.php`

---

## Task 11: Add Request Validation to VoiceRoutingController (CR-7)

**File:** `app/Http/Controllers/Voice/VoiceRoutingController.php:34-82`
**Risk:** Unvalidated input reaches business logic

### Plan
1. Create three FormRequest classes:
   - `App\Http\Requests\Voice\HandleInboundRequest`
   - `App\Http\Requests\Voice\RingGroupCallbackFormRequest`
   - `App\Http\Requests\Voice\IvrInputFormRequest`

2. `HandleInboundRequest` rules:
   ```php
   public function rules(): array
   {
       return [
           '_organization_id' => ['required', 'integer'],
           'To' => ['required_without:to', 'string'],
           'to' => ['required_without:To', 'string'],
           'From' => ['nullable', 'string'],
           'from' => ['nullable', 'string'],
           'CallSid' => ['nullable', 'string'],
           'Domain' => ['nullable', 'string'],
       ];
   }
   ```

3. Update controller method signatures to use the new FormRequest types
4. The existing `VoiceRoutingRequest`, `RingGroupCallbackRequest`, `IvrInputRequest` may already exist - check before creating duplicates

### Files Changed
- Up to 3 new FormRequest files (or update existing ones)
- `app/Http/Controllers/Voice/VoiceRoutingController.php`

---

## Task 12: Add Tenant Verification to Webhook Routing (CR-8)

**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:207-334`
**Risk:** Cross-tenant routing if extension object source is untrusted

### Plan
1. Add organization_id verification at the entry of each routing method:
   ```php
   private function routeExtensionDirectly(Extension $extension, $request, int $organizationId): string
   {
       if ($extension->organization_id !== $organizationId) {
           Log::warning('Extension org mismatch in direct routing', [
               'extension_id' => $extension->id,
               'extension_org' => $extension->organization_id,
               'expected_org' => $organizationId,
           ]);
           return $this->routingService->generateErrorCxml('Extension not found');
       }
       // ... existing routing logic
   }
   ```
2. Apply to all private routing methods:
   - `routeExtensionDirectly`
   - `routeUserExtensionDirectly`
   - `routeConferenceExtensionDirectly`
   - `routeAiExtensionDirectly`
   - `routeQueueExtensionDirectly`
   - `routeGroupExtensionDirectly`

### Files Changed
- `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`

---

## Task 13: Standardize Error Response Formats (CR-9)

**Files:** Multiple controllers
**Risk:** Frontend cannot reliably parse API errors

### Plan
1. Define a standard error response structure:
   ```json
   {
     "error": {
       "code": "VALIDATION_ERROR",
       "message": "Human-readable message",
       "details": {}
     }
   }
   ```
2. Create a `HandlesApiErrors` trait (or extend existing `ApiRequestHandler` trait):
   ```php
   trait HandlesApiErrors
   {
       protected function errorResponse(string $message, int $status = 500, ?string $code = null, array $details = []): JsonResponse
       {
           return response()->json([
               'error' => [
                   'code' => $code ?? $this->httpStatusToCode($status),
                   'message' => $message,
                   'details' => $details ?: null,
               ],
           ], $status);
       }
   }
   ```
3. Update `AbstractApiCrudController` to use the trait (most controllers inherit from it)
4. Update standalone controllers (`ExtensionController`, `RecordingsController`, `SessionUpdateController`) to use the trait
5. For webhook controllers that return CXML, keep XML responses but standardize the error CXML format

### Files Changed
- `app/Http/Controllers/Traits/HandlesApiErrors.php` (new or extend ApiRequestHandler)
- `app/Http/Controllers/Api/AbstractApiCrudController.php`
- Controllers that don't extend AbstractApiCrudController

---

## Task 14: Fix Race Condition in Ring Group Routing (CR-10)

**File:** `app/Services/CallRouting/CallRoutingService.php:225-228`
**Risk:** Stale ring group member data under concurrent modification

### Plan
1. Within the lock callback, use `lockForUpdate()` to acquire a database-level row lock:
   ```php
   $cxml = $this->resilientCache->lock($lockKey, function () use ($ringGroup, $didId) {
       // Use database lock to prevent stale reads
       $freshGroup = RingGroup::where('id', $ringGroup->id)
           ->lockForUpdate()
           ->with('members.extension')
           ->first();

       if (!$freshGroup) {
           return $this->generateErrorCxml('Ring group not found');
       }

       return $this->buildRingGroupCxml($freshGroup, $didId);
   });
   ```
2. Ensure the database transaction wraps the lock callback
3. Keep the Redis lock for distributed coordination; add DB lock for data consistency

### Files Changed
- `app/Services/CallRouting/CallRoutingService.php`

---

## Task 15: Consolidate Duplicate Routing Logic (CR-11)

**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:207-334`
**Risk:** Logic drift between two implementations

### Plan
1. Identify all routing methods in `CloudonixWebhookController` that duplicate `VoiceRoutingManager` logic:
   - `routeExtensionDirectly` -> duplicates `VoiceRoutingManager::routeToExtension`
   - `routeConferenceExtensionDirectly` -> duplicates conference routing
   - `routeAiExtensionDirectly` -> duplicates AI routing
   - etc.
2. Replace each with a delegation call to `VoiceRoutingManager`:
   ```php
   private function routeExtensionDirectly(Extension $extension, $request, int $organizationId): string
   {
       return $this->routingService->routeToExtension($extension, $request, $organizationId);
   }
   ```
3. If `VoiceRoutingManager` doesn't have exact equivalent methods, add them
4. Eventually, the private routing methods in `CloudonixWebhookController` become thin wrappers or can be removed entirely
5. This depends on Task 12 (tenant verification) being done first

### Files Changed
- `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`
- `app/Services/VoiceRouting/VoiceRoutingManager.php` (add public methods if needed)

---

## Task 16: Add Missing Database Indexes (CR-12)

**Files:** Database migrations
**Risk:** Slow queries under call volume

### Plan
1. Create a single migration for all missing indexes:
   ```bash
   php artisan make:migration add_performance_indexes
   ```
2. Add the following indexes:
   ```php
   // Extensions - hot path during call routing
   Schema::table('extensions', function (Blueprint $table) {
       $table->index(['organization_id', 'extension_number', 'status']);
   });

   // Ring group members - member lookups
   Schema::table('ring_group_members', function (Blueprint $table) {
       $table->index(['ring_group_id', 'extension_id']);
   });

   // Session updates - active call queries
   Schema::table('session_updates', function (Blueprint $table) {
       $table->index(['organization_id', 'session_id', 'status']);
       $table->index(['organization_id', 'status', 'updated_at']);
   });

   // Call logs - chronological queries
   Schema::table('call_logs', function (Blueprint $table) {
       $table->index(['organization_id', 'created_at']);
   });
   ```
3. Verify indexes don't already exist before adding (check existing migrations)
4. Write `down()` method to drop indexes for rollback safety

### Files Changed
- 1 new migration file

---

## Task 17: Move Magic Numbers to Config (CR-13)

**Files:** Multiple
**Risk:** Configuration changes require code edits

### Plan
1. Create `config/voice_routing.php`:
   ```php
   return [
       'lock_timeout_seconds' => env('VOICE_LOCK_TIMEOUT', 30),
       'max_retry_attempts' => env('VOICE_MAX_RETRIES', 3),
       'lock_block_seconds' => env('VOICE_LOCK_BLOCK', 3),
       'idempotency_ttl_seconds' => env('VOICE_IDEMPOTENCY_TTL', 3600),
       'cache_ttl_seconds' => env('VOICE_CACHE_TTL', 300),
       'no_answer_timeout_seconds' => env('VOICE_NO_ANSWER_TIMEOUT', 30),
   ];
   ```
2. Replace hardcoded values in `VoiceRoutingManager`, `CallRoutingService`, `CallStateManager` with `config('voice_routing.xxx')`
3. Add corresponding entries to `.env.example`

### Files Changed
- `config/voice_routing.php` (new)
- `app/Services/VoiceRouting/VoiceRoutingManager.php`
- `app/Services/CallRouting/CallRoutingService.php`
- `app/Services/CallStateManager/CallStateManager.php`
- `.env.example`

---

## Task 18: Add Rate Limiting to Password Retrieval (CR-14)

**File:** `routes/api.php:207-208`
**Risk:** Brute-force password retrieval attempts

### Plan
1. Add `sensitive-operations` middleware to the getPassword route:
   ```php
   Route::get('password', [ExtensionPasswordController::class, 'getPassword'])
       ->middleware('sensitive-operations')
       ->name('extensions.password');
   ```
2. This is a one-line change in `routes/api.php`
3. Verify the middleware alias `sensitive-operations` is registered in the kernel

### Files Changed
- `routes/api.php`

---

## Task 19: Remove Stack Traces from Error Responses (CR-15)

**File:** `app/Http/Controllers/Webhooks/CloudonixWebhookController.php:491-505`
**Risk:** Internal implementation details exposed to external callers

### Plan
1. Remove `trace` from all JSON error responses in webhook controllers:
   ```php
   // Before:
   return response()->json([
       'error' => 'Session update processing failed',
       'message' => $e->getMessage(),
       'trace' => $e->getTraceAsString(),
   ], 500);

   // After:
   return response()->json([
       'error' => 'Session update processing failed',
       'message' => 'An internal error occurred.',
   ], 500);
   ```
2. Keep detailed exception in log entries (already there)
3. Grep the entire codebase for `getTraceAsString()` in response bodies:
   ```
   grep -rn "getTraceAsString" app/Http/
   ```
4. Fix all occurrences

### Files Changed
- `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`
- Any other controllers returning traces

---

## Task 20: Fix RecordingPolicy Role Check (SEC-13)

**File:** `app/Policies/RecordingPolicy.php:14-54`
**Risk:** Role check may silently fail due to type mismatch

### Plan
1. Replace all `$user->hasRole(['owner', 'admin'])` calls with proper enum-based checks:
   ```php
   // Before:
   return $user->hasRole(['owner', 'admin']) && $user->organization_id !== null;

   // After:
   return ($user->isOwner() || $user->isPBXAdmin()) && $user->organization_id !== null;
   ```
2. Apply to all 5 methods in `RecordingPolicy`: `viewAny`, `view`, `create`, `update`, `delete`
3. Verify `isOwner()` and `isPBXAdmin()` methods exist on the `User` model
4. Check other policies for the same pattern (they should use `isOwner()` / `isPBXAdmin()` as documented)

### Files Changed
- `app/Policies/RecordingPolicy.php`

---

## Execution Order

Group by dependency and risk:

**Wave 1 (Quick security fixes - days 1-3):**
1. Task 1 (X-Organization-ID header) - 15 min
2. Task 7 (Admin rate limit) - 30 min
3. Task 18 (Password rate limiting) - 5 min
4. Task 19 (Stack traces) - 30 min
5. Task 20 (RecordingPolicy) - 15 min

**Wave 2 (Security hardening - days 3-7):**
6. Task 2 (Mask API keys)
7. Task 3 (CDR auth)
8. Task 4 (Recording tenant check)
9. Task 5 (Docker ports)
10. Task 6 (Default credentials)

**Wave 3 (Performance & code quality - days 7-14):**
11. Task 9 (N+1 + indexes, includes CR-12)
12. Task 10 (Logging verbosity)
13. Task 16 (Database indexes - if not done in Task 9)
14. Task 17 (Magic numbers to config)

**Wave 4 (Refactoring - days 14-30):**
15. Task 8 (ExtensionController refactor)
16. Task 11 (Request validation)
17. Task 12 (Webhook tenant verification)
18. Task 14 (Ring group race condition)
19. Task 15 (Consolidate routing logic) - depends on Task 12
20. Task 13 (Error response standardization)

### Validation After Each Wave
- Run full test suite: `php artisan test`
- Verify no regressions in webhook processing
- Check frontend still functions for affected features
