# Workplan: Medium Severity Remediation

**Date:** 2026-02-06
**Source:** Full Code Review & Security Audit (2026-02-06)
**Priority:** MEDIUM-TERM (30-90 days)
**Findings:** 27 Medium issues (CR-16 through CR-28, SEC-12 through SEC-20)
**Depends on:** Critical and High workplan completion

---

## Overview

These 27 findings cover code quality improvements, hardening measures, and testing gaps. They are organized into thematic groups for efficient execution.

---

## Group A: Input Validation & Injection Hardening

### Task A1: Add Rate Limiting to Registration Validation (SEC-12)

**File:** `app/Http/Controllers/Api/RegisterController.php:101-123`
**File:** `routes/api.php:154`

**Plan:**
1. Add throttle middleware to the validate endpoint:
   ```php
   Route::get('/register/validate', [RegisterController::class, 'validateRegistration'])
       ->middleware('throttle:10,1')  // 10 requests per minute
       ->name('auth.register.validate');
   ```
2. Consider making responses ambiguous: instead of `"admin_email_available": false`, return `"admin_email_available": true` always but with a delay (to prevent timing attacks) - this is optional and may hurt UX
3. Add the rate limit to both IP-based and global throttles

**Files:** `routes/api.php`

---

### Task A2: Add XML Encoding to CXML Bad Request Response (SEC-16)

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php:222-228`

**Plan:**
1. Apply XML encoding to the message parameter:
   ```php
   private function badRequestResponse(string $message): Response
   {
       $safeMessage = htmlspecialchars($message, ENT_XML1, 'UTF-8');
       $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
           '<Response>' . "\n" .
           "  <Say language=\"en-US\">Bad request. {$safeMessage}.</Say>" . "\n" .
           '  <Hangup/>' . "\n" .
           '</Response>';
       // ...
   }
   ```
2. Apply the same pattern to any other CXML string construction outside of `CxmlResponse` service
3. Grep for `<Say` and `<Response>` string concatenation in middleware/controllers to find all instances

**Files:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`

---

### Task A3: Sanitize Recording Download Filename (SEC-17)

**File:** `app/Http/Controllers/Api/RecordingsController.php:339`

**Plan:**
1. Create a filename sanitization helper:
   ```php
   private function sanitizeFilename(string $filename): string
   {
       // Remove path separators and control characters
       $filename = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1f]/', '_', $filename);
       // Remove leading dots (hidden files)
       $filename = ltrim($filename, '.');
       // Limit length
       return substr($filename, 0, 255) ?: 'download';
   }
   ```
2. Apply to Content-Disposition header:
   ```php
   $safeName = $this->sanitizeFilename($recording->original_filename ?? $recording->file_path);
   $headers['Content-Disposition'] = "attachment; filename=\"{$safeName}\"";
   ```
3. Consider using RFC 5987 encoding for non-ASCII filenames:
   ```php
   $headers['Content-Disposition'] = "attachment; filename*=UTF-8''" . rawurlencode($safeName);
   ```

**Files:** `app/Http/Controllers/Api/RecordingsController.php`

---

### Task A4: Validate Configuration JSON Field (CR-22)

**File:** `app/Models/Extension.php`

**Plan:**
1. Create a custom cast or validation rule for the `configuration` field
2. Define allowed configuration schemas per extension type:
   ```php
   private const CONFIG_SCHEMA = [
       'user' => ['sip_username', 'sip_password'],
       'conference' => ['conference_room_id', 'pin'],
       'ai_assistant' => ['ai_assistant_id', 'provider'],
       'queue' => ['queue_id', 'timeout'],
       'group' => ['ring_group_id'],
   ];
   ```
3. Add validation in `StoreExtensionRequest` and `UpdateExtensionRequest`:
   ```php
   'configuration' => ['nullable', 'array'],
   'configuration.*' => ['string', 'max:500'],
   ```
4. Reject unknown keys in the configuration based on extension type

**Files:** `app/Http/Requests/Extension/StoreExtensionRequest.php`, `UpdateExtensionRequest.php`

---

### Task A5: Validate Enum Values in AppliesFilters (CR-24)

**File:** `app/Http/Controllers/Traits/AppliesFilters.php`

**Plan:**
1. Read the `AppliesFilters` trait to understand current enum handling
2. Add explicit enum validation when filter type is 'enum':
   ```php
   case 'enum':
       $enumClass = $config['enum'];
       if (!$enumClass::tryFrom($value)) {
           // Skip invalid enum values silently or return validation error
           continue;
       }
       $query->{$config['scope']}($enumClass::from($value));
       break;
   ```
3. Add test cases for invalid enum filter values

**Files:** `app/Http/Controllers/Traits/AppliesFilters.php`

---

## Group B: Session & Authentication Hardening

### Task B1: Enable Session Encryption (SEC-18)

**File:** `.env.example`, `config/session.php`

**Plan:**
1. Update `.env.example`:
   ```
   SESSION_ENCRYPT=true  # Always encrypt in production
   ```
2. In `config/session.php`, change the default:
   ```php
   'encrypt' => env('SESSION_ENCRYPT', true),  // Default to true
   ```
3. Keep `SESSION_ENCRYPT=false` in local `.env` for development if desired (performance)
4. Document the change in deployment notes

**Files:** `.env.example`, `config/session.php`

---

### Task B2: Enforce Secure Cookies (SEC-19)

**File:** `config/session.php:172`

**Plan:**
1. Set a sensible default for secure cookies:
   ```php
   'secure' => env('SESSION_SECURE_COOKIE', !in_array(env('APP_ENV'), ['local', 'testing'])),
   ```
2. This automatically enables secure cookies in production while keeping HTTP working in development
3. Add `SESSION_SECURE_COOKIE=true` to `.env.example` with a comment

**Files:** `config/session.php`, `.env.example`

---

### Task B3: Validate Development Rate Limits (SEC-20)

**File:** `.env`, `.env.example`

**Plan:**
1. Add a deployment validation script or artisan command:
   ```bash
   php artisan config:validate
   ```
2. The command checks:
   - `RATE_LIMIT_API` <= 120 (warn if > 120)
   - `RATE_LIMIT_AUTH` <= 10 (warn if > 10)
   - `RATE_LIMIT_SENSITIVE` <= 20 (warn if > 20)
3. Alternative: Add a check in `AppServiceProvider::boot()` that logs a warning if rate limits exceed production thresholds:
   ```php
   if (app()->environment('production') && config('rate_limiting.api') > 120) {
       Log::warning('Production rate limit RATE_LIMIT_API is unusually high', [
           'value' => config('rate_limiting.api'),
       ]);
   }
   ```
4. Update `.env.example` with production-appropriate values and comments

**Files:** `app/Providers/AppServiceProvider.php` or new artisan command, `.env.example`

---

## Group C: Multi-Tenancy Hardening

### Task C1: Create Tenant-Safe Query Helper (SEC-14)

**Files:** Multiple in `app/Services/VoiceRouting/`, `app/Http/Controllers/Webhooks/`

**Plan:**
1. Create a trait `App\Models\Traits\TenantScopedQueries`:
   ```php
   trait TenantScopedQueries
   {
       /**
        * Query without the global organization scope but with explicit org filter.
        * This prevents accidentally omitting the organization_id filter.
        */
       public static function forOrganization(int $organizationId): Builder
       {
           return static::withoutGlobalScope(OrganizationScope::class)
               ->where('organization_id', $organizationId);
       }
   }
   ```
2. Add the trait to all models that use `OrganizationScope`: `Extension`, `DidNumber`, `RingGroup`, `BusinessHoursSchedule`, `ConferenceRoom`, `IvrMenu`, `AiAssistant`, `Recording`, `CallLog`, etc.
3. Replace `withoutGlobalScope(OrganizationScope::class)->where('organization_id', $orgId)` patterns with `Model::forOrganization($orgId)`:
   ```php
   // Before:
   Extension::withoutGlobalScope(OrganizationScope::class)
       ->where('organization_id', $orgId)
       ->where('extension_number', $to)
       ->first();

   // After:
   Extension::forOrganization($orgId)
       ->where('extension_number', $to)
       ->first();
   ```
4. Update all 50+ instances across the codebase (this is a large but mechanical change)
5. The `forOrganization` method makes it impossible to forget the tenant filter

**Files:**
- `app/Models/Traits/TenantScopedQueries.php` (new)
- All models with OrganizationScope (add trait)
- All files with `withoutGlobalScope(OrganizationScope::class)` (update queries)

---

## Group D: Resilience & Performance

### Task D1: Fix Ring Group Fallback Validation (CR-16)

**File:** `app/Http/Controllers/Api/RingGroupController.php:128-168`

**Plan:**
1. Validate `fallback_action` before clearing fallback IDs:
   ```php
   $validActions = ['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'voicemail'];
   if (!in_array($action, $validActions)) {
       // Don't clear existing fallback references for invalid actions
       return $validated;
   }
   ```
2. Alternatively, use the `RingGroupFallbackAction` enum for validation:
   ```php
   $fallbackAction = RingGroupFallbackAction::tryFrom($action);
   if (!$fallbackAction) {
       return $validated; // Preserve existing fallback config
   }
   ```

**Files:** `app/Http/Controllers/Api/RingGroupController.php`

---

### Task D2: Add Queue Worker Health Check (CR-17)

**File:** `routes/api.php:53-59`

**Plan:**
1. Add queue health to the `/health` endpoint:
   ```php
   Route::get('/health', function () {
       $queueHealth = true;
       try {
           // Check if queue is responsive by checking last heartbeat
           $lastHeartbeat = Cache::get('queue:heartbeat');
           $queueHealth = $lastHeartbeat && $lastHeartbeat > now()->subMinutes(5)->timestamp;
       } catch (\Exception $e) {
           $queueHealth = false;
       }

       return response()->json([
           'status' => 'ok',
           'queue' => $queueHealth ? 'healthy' : 'degraded',
           'timestamp' => now()->toIso8601String(),
       ]);
   });
   ```
2. Add a heartbeat to the queue worker by scheduling a periodic job:
   ```php
   // In app/Console/Kernel.php schedule():
   $schedule->call(function () {
       Cache::put('queue:heartbeat', now()->timestamp, 600);
   })->everyMinute();
   ```
3. Alternative: check queue depth using `Queue::size('default')`

**Files:** `routes/api.php`, `app/Console/Kernel.php` or scheduler config

---

### Task D3: Prevent Cache Stampede (CR-18)

**File:** `app/Services/VoiceRouting/VoiceRoutingCacheService.php`

**Plan:**
1. Implement cache locking on miss (singleflight pattern):
   ```php
   public function getExtension(int $orgId, string $extensionNumber): ?Extension
   {
       $cacheKey = "voice:ext:{$orgId}:{$extensionNumber}";

       return Cache::remember($cacheKey, 300, function () use ($orgId, $extensionNumber) {
           return Cache::lock("lock:cache:{$cacheKey}", 10)
               ->block(5, function () use ($orgId, $extensionNumber) {
                   // Check cache again in case another process populated it
                   // ... DB query ...
               });
       });
       // If lock can't be acquired, fall through to DB query
   }
   ```
2. Alternative: use probabilistic early expiration (cache items refresh before they expire, with some randomness to spread load)
3. Apply to the most frequently accessed cache keys: extensions, DID routing config, business hours

**Files:** `app/Services/VoiceRouting/VoiceRoutingCacheService.php`

---

### Task D4: Add Database Connection Retry Logic (CR-19)

**Plan:**
1. Configure Laravel's built-in `sticky` and `retry` database options in `config/database.php`:
   ```php
   'mysql' => [
       // ...
       'options' => [
           PDO::ATTR_EMULATE_PREPARES => true,
           PDO::ATTR_TIMEOUT => 5,
       ],
   ],
   ```
2. For webhook handlers, wrap DB operations with retry logic:
   ```php
   retry(3, function () use ($data) {
       DB::transaction(function () use ($data) {
           // ... database operations ...
       });
   }, 100); // 100ms between retries
   ```
3. Laravel's queue workers already have built-in retry via `--tries` flag; verify it's configured in docker-compose

**Files:** `config/database.php`, webhook handlers that do DB writes

---

### Task D5: Centralize Request ID in Logging Context (CR-20)

**Plan:**
1. Create middleware `App\Http\Middleware\SetRequestContext`:
   ```php
   public function handle(Request $request, Closure $next): Response
   {
       $requestId = $request->header('X-Request-ID', Str::uuid()->toString());
       Log::shareContext(['request_id' => $requestId]);
       $request->attributes->set('request_id', $requestId);
       $response = $next($request);
       $response->headers->set('X-Request-ID', $requestId);
       return $response;
   }
   ```
2. Register as global middleware (before other middleware)
3. Remove manual `$this->getRequestId()` calls from controllers (they can use the shared context)
4. All log entries will automatically include `request_id`

**Files:** `app/Http/Middleware/SetRequestContext.php` (new), kernel/bootstrap middleware registration

---

### Task D6: Add Request Size Limits (CR-21)

**Plan:**
1. Add size validation to webhook form requests:
   ```php
   // In CallInitiatedRequest or a middleware
   protected function prepareForValidation(): void
   {
       if (strlen($this->getContent()) > 65536) { // 64KB
           abort(413, 'Request payload too large');
       }
   }
   ```
2. For file uploads (recordings), add to `php.ini` or nginx config:
   ```
   upload_max_filesize = 50M
   post_max_size = 55M
   ```
3. Add nginx client_max_body_size directive:
   ```nginx
   client_max_body_size 50m;
   ```
4. Verify existing configuration in `docker/nginx/default.conf`

**Files:** Webhook form requests, `docker/nginx/default.conf`, `docker/php/php.ini`

---

### Task D7: Verify Circuit Breaker Usage (CR-27)

**Plan:**
1. Read `app/Services/CircuitBreaker/CircuitBreaker.php` to understand the implementation
2. Check if `CloudonixClient` uses the circuit breaker:
   - Grep for `CircuitBreaker` usage in `app/Services/CloudonixClient/`
3. If not used, wrap Cloudonix API calls:
   ```php
   $result = $this->circuitBreaker->call('cloudonix-api', function () use ($params) {
       return $this->httpClient->post($url, $params);
   });
   ```
4. Configure thresholds in `config/circuit-breaker.php`:
   - Failure threshold: 5 failures
   - Recovery timeout: 30 seconds
   - Half-open max attempts: 3

**Files:** `app/Services/CloudonixClient/CloudonixClient.php`, `config/circuit-breaker.php`

---

### Task D8: Add Request Timeout Configuration (CR-28)

**Plan:**
1. Configure HTTP client timeouts for Cloudonix API calls:
   ```php
   // In CloudonixClient constructor or service provider
   $this->httpClient = Http::timeout(10)        // 10s connection timeout
       ->connectTimeout(5)                        // 5s connect timeout
       ->retry(2, 100);                           // 2 retries, 100ms between
   ```
2. Add to `.env.example`:
   ```
   CLOUDONIX_API_TIMEOUT=10
   CLOUDONIX_API_CONNECT_TIMEOUT=5
   ```
3. Add to `config/cloudonix.php`:
   ```php
   'timeout' => env('CLOUDONIX_API_TIMEOUT', 10),
   'connect_timeout' => env('CLOUDONIX_API_CONNECT_TIMEOUT', 5),
   ```

**Files:** `app/Services/CloudonixClient/CloudonixClient.php`, `config/cloudonix.php`, `.env.example`

---

## Group E: Testing & Audit

### Task E1: Add Migration Rollback Tests (CR-23)

**Plan:**
1. Create a test that runs all migrations up and down:
   ```php
   public function test_all_migrations_can_rollback(): void
   {
       Artisan::call('migrate:fresh');
       Artisan::call('migrate:rollback', ['--step' => 999]);
       // If no exception, all down() methods work
       $this->assertTrue(true);
   }
   ```
2. Better approach: test each migration individually for data safety
3. This can be a CI-only test (slow, not needed in local dev)

**Files:** `tests/Feature/MigrationRollbackTest.php` (new)

---

### Task E2: Audit AuditLogger Coverage (CR-25)

**Plan:**
1. Grep all controllers for `AuditLogger` usage
2. List all create/update/delete operations that should be audited
3. Compare lists to find gaps
4. Add `AuditLogger` calls for any missing operations
5. Key operations that MUST be audited:
   - User CRUD
   - Extension CRUD
   - DID routing changes
   - Ring group changes
   - Settings/API key changes
   - Password resets
   - Login/logout

**Files:** Controllers missing audit logging (TBD after audit)

---

### Task E3: Add Business Hours Timezone Tests (CR-26)

**Plan:**
1. Create test cases for:
   - DST spring-forward transition (clock skips 2am -> 3am)
   - DST fall-back transition (clock repeats 1am -> 2am)
   - UTC+13 / UTC-12 edge timezones
   - Midnight crossover in different timezones
   - Business hours that span midnight (e.g., night shift)
2. Use Carbon's `setTestNow()` to simulate specific dates/times
3. Test the `BusinessHoursService::isWithinBusinessHours()` method

**Files:** `tests/Unit/Services/BusinessHoursTimezoneTest.php` (new)

---

### Task E4: Add Critical Path Test Coverage

**Plan:**
1. **Webhook idempotency tests** (after CR-1 implementation):
   - Test duplicate CallSid returns cached response
   - Test concurrent webhooks are serialized
2. **Cross-tenant isolation tests:**
   - Test voice routing cannot access extensions from another org
   - Test DID lookup is scoped by organization
3. **Ring group concurrent routing tests:**
   - Test simultaneous calls to same ring group
   - Test round-robin state management under concurrency
4. **Password retrieval security tests:**
   - Test rate limiting is enforced
   - Test response headers include no-cache directives

**Files:** Multiple new test files in `tests/Feature/` and `tests/Unit/`

---

## Group F: CORS & Infrastructure

### Task F1: Environment-Conditional CORS (SEC-26 - moved from Low for grouping)

**File:** `config/cors.php:24-30`

**Plan:**
1. Make localhost origins conditional:
   ```php
   'allowed_origins' => array_filter([
       env('FRONTEND_URL', 'http://localhost:3000'),
       ...array_filter([
           app()->environment('local', 'testing') ? 'http://localhost:3000' : null,
           app()->environment('local', 'testing') ? 'http://127.0.0.1:3000' : null,
           app()->environment('local', 'testing') ? 'http://localhost' : null,
           app()->environment('local', 'testing') ? 'http://127.0.0.1' : null,
       ]),
   ]),
   ```
2. Note: `app()` helper may not be available during config caching. Alternative approach - use env var:
   ```php
   'allowed_origins' => array_filter([
       env('FRONTEND_URL'),
       env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
       env('APP_ENV') === 'local' ? 'http://127.0.0.1:3000' : null,
   ]),
   ```

**Files:** `config/cors.php`

---

## Execution Order

**Sprint 1 (days 1-7): Quick fixes**
- A1 (rate limit registration) - 15 min
- A2 (XML encoding) - 15 min
- A3 (filename sanitization) - 30 min
- B1 (session encryption) - 10 min
- B2 (secure cookies) - 10 min
- D1 (fallback validation) - 20 min
- F1 (CORS) - 15 min

**Sprint 2 (days 7-21): Hardening**
- C1 (tenant-safe queries) - 2-3 hours (large mechanical change)
- D5 (request ID middleware) - 1 hour
- D6 (request size limits) - 30 min
- D7 (circuit breaker) - 1 hour
- D8 (timeouts) - 30 min
- A4 (configuration validation) - 1 hour
- A5 (enum filter validation) - 30 min

**Sprint 3 (days 21-45): Testing & resilience**
- D2 (queue health check) - 1 hour
- D3 (cache stampede) - 2 hours
- D4 (DB retry logic) - 1 hour
- B3 (rate limit validation) - 1 hour
- E1 (migration rollback tests) - 1 hour
- E2 (audit logger coverage) - 2 hours
- E3 (timezone tests) - 2 hours
- E4 (critical path tests) - 4 hours
