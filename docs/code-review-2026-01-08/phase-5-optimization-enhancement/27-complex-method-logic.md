# Issue #27: Complex Method Logic

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 4-6 hours
**Assigned:** Unassigned

## Problem Description

Methods in VoiceRoutingManager handle multiple responsibilities (validation, lookup, execution), making them difficult to understand and modify.

**Location:** `app/Services/VoiceRoutingManager.php`

## Impact Assessment

- **Severity:** Normal - Code complexity
- **Scope:** Core routing logic
- **Risk:** Medium - Maintenance difficulty
- **Dependencies:** Call routing functionality

## Solution Overview

Break down complex methods into smaller, focused methods with single responsibilities.

## Implementation Steps

### Phase 1: Method Analysis (1 hour)
1. Identify complex methods (>50 lines)
2. Map responsibilities within each method
3. Plan extraction strategy

### Phase 2: Extract Private Methods (2-3 hours)
1. Create focused private methods
2. Extract validation logic
3. Extract business logic
4. Extract response generation

### Phase 3: Refactor Main Methods (1 hour)
1. Simplify main method logic
2. Use early returns for readability
3. Add comprehensive comments

### Phase 4: Testing and Documentation (1 hour)
1. Update tests for new methods
2. Add method documentation
3. Verify functionality preserved

## Code Changes

### File: `app/Services/VoiceRoutingManager.php` (Refactored)

**Before (Complex Method):**
```php
public function handleInboundCall(array $webhookData): CxmlResponse
{
    // 50+ lines of mixed logic
    // DID validation, business hours checking, extension lookup, routing logic
    // Hard to understand and maintain
}
```

**After (Clean Methods):**
```php
public function handleInboundCall(array $webhookData): CxmlResponse
{
    $this->validateWebhookData($webhookData);
    
    $did = $this->resolveDidNumber($webhookData['did']);
    if (!$did) {
        return $this->createErrorResponse('Invalid DID');
    }
    
    if ($this->isWithinBusinessHours($did)) {
        return $this->routeToBusinessHoursTarget($did, $webhookData);
    }
    
    return $this->routeToAfterHoursTarget($did, $webhookData);
}

private function validateWebhookData(array $data): void
{
    // Validation logic only
}

private function resolveDidNumber(string $didNumber): ?DidNumber
{
    // DID resolution only
}

private function isWithinBusinessHours(DidNumber $did): bool
{
    // Business hours logic only
}

private function routeToBusinessHoursTarget(DidNumber $did, array $context): CxmlResponse
{
    // Business hours routing only
}

private function routeToAfterHoursTarget(DidNumber $did, array $context): CxmlResponse
{
    // After hours routing only
}

private function createErrorResponse(string $message): CxmlResponse
{
    // Error response generation only
}
```

## Verification Steps

1. **Functionality Test:**
   - Test all routing scenarios work
   - Verify CXML responses unchanged
   - Check error handling

2. **Code Quality:**
   ```bash
   # Check method lengths
   find app/Services -name "*.php" -exec wc -l {} \; | sort -nr
   ```

3. **Test Coverage:**
   ```bash
   php artisan test --filter=VoiceRoutingManagerTest
   ```

## Rollback Plan

If refactoring breaks functionality:
1. Keep original methods as backup
2. Gradually migrate logic
3. Test each extracted method individually

## Testing Requirements

- [ ] All routing scenarios work
- [ ] Method complexity reduced
- [ ] Tests pass
- [ ] Code readability improved

## Documentation Updates

- Document new method structure
- Update code comments
- Mark as completed in master work plan

## Completion Criteria

- [ ] Complex methods broken down
- [ ] Single responsibility principle followed
- [ ] Code readability improved
- [ ] Code reviewed and approved

---

**Estimated Completion:** 4-6 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #28: Mixed Concerns in Controllers

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 3-4 hours
**Assigned:** Unassigned

## Problem Description

Controllers handle both HTTP concerns and business logic, making them difficult to test and reuse.

**Location:** Various Laravel controllers

## Impact Assessment

- **Severity:** Normal - Architecture issue
- **Scope:** Controller layer
- **Risk:** Medium - Testing and maintenance difficulty
- **Dependencies:** HTTP request/response handling

## Solution Overview

Extract business logic to service classes and implement thin controllers following Laravel conventions.

## Implementation Steps

### Phase 1: Controller Analysis (1 hour)
1. Identify controllers with business logic
2. Map responsibilities (HTTP vs business)
3. Plan extraction strategy

### Phase 2: Create Service Classes (1-2 hours)
1. Extract business logic to services
2. Create focused service methods
3. Implement proper dependency injection

### Phase 3: Refactor Controllers (1 hour)
1. Simplify controller methods
2. Use service classes for business logic
3. Focus on HTTP concerns only

### Phase 4: Update Tests (30 minutes)
1. Update controller tests
2. Add service tests
3. Verify functionality preserved

## Code Changes

### Example: UsersController Refactoring

**Before (Mixed Concerns):**
```php
public function store(Request $request)
{
    // Validation
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'role' => 'required|in:admin,user',
    ]);

    // Business logic mixed with HTTP
    $user = new User();
    $user->name = $validated['name'];
    $user->email = $validated['email'];
    $user->role = $validated['role'];
    $user->organization_id = auth()->user()->organization_id;
    $user->save();

    // Response logic
    return response()->json([
        'user' => $user,
        'message' => 'User created successfully'
    ], 201);
}
```

**After (Separated Concerns):**

#### New File: `app/Services/UserService.php`
```php
<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function createUser(array $data, int $organizationId): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'organization_id' => $organizationId,
        ]);
    }
}
```

#### File: `app/Http/Controllers/Api/UsersController.php`
```php
public function store(CreateUserRequest $request, UserService $userService)
{
    $user = $userService->createUser(
        $request->validated(),
        auth()->user()->organization_id
    );

    return new UserResource($user);
}
```

#### New File: `app/Http/Requests/CreateUserRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(UserRole::values())],
        ];
    }

    public function authorize(): bool
    {
        return auth()->user()->can('create', User::class);
    }
}
```

## Verification Steps

1. **API Functionality:**
   - Test all endpoints work
   - Verify responses unchanged
   - Check error handling

2. **Test Coverage:**
   ```bash
   php artisan test --filter=UsersControllerTest
   ```

3. **Code Organization:**
   - Controllers should be thin (<20 lines per method)
   - Business logic in services
   - Validation in form requests

## Rollback Plan

If refactoring causes issues:
1. Keep mixed logic temporarily
2. Gradually extract services
3. Test each change individually

## Testing Requirements

- [ ] All controller endpoints work
- [ ] Business logic properly extracted
- [ ] Test coverage maintained
- [ ] Code organization improved

## Documentation Updates

- Document service layer architecture
- Update controller guidelines
- Mark as completed in master work plan

## Completion Criteria

- [ ] Business logic extracted to services
- [ ] Controllers focused on HTTP concerns
- [ ] Form request validation implemented
- [ ] Code reviewed and approved

---

**Estimated Completion:** 3-4 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #29: Inconsistent Error Handling Patterns

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

Different approaches to error handling across the codebase lead to inconsistent user experience and debugging.

**Location:** Various controllers and services

## Impact Assessment

- **Severity:** Normal - Developer experience
- **Scope:** Error handling throughout application
- **Risk:** Low-Medium - User experience inconsistency
- **Dependencies:** Exception handling, logging

## Solution Overview

Create standard error response format and implement consistent exception handling patterns.

## Implementation Steps

### Phase 1: Error Pattern Analysis (30 minutes)
1. Identify different error handling approaches
2. Map error types and responses
3. Plan standardization strategy

### Phase 2: Create Error Standards (1 hour)
1. Define standard error response format
2. Create error response helper
3. Implement exception handlers

### Phase 3: Update Error Handling (1 hour)
1. Refactor controllers to use standard patterns
2. Update service error handling
3. Implement consistent logging

### Phase 4: Testing and Documentation (30 minutes)
1. Test error scenarios
2. Update error documentation
3. Verify consistency

## Code Changes

### New File: `app/Exceptions/ApiException.php`
```php
<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    protected $statusCode;
    protected $errorCode;
    protected $errors;

    public function __construct(
        string $message,
        int $statusCode = 400,
        string $errorCode = 'API_ERROR',
        array $errors = []
    ) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->errors = $errors;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

### New File: `app/Services/ApiResponseService.php`
```php
<?php

namespace App\Services;

class ApiResponseService
{
    public static function success($data = null, string $message = null, int $status = 200): array
    {
        return [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'status' => $status,
        ];
    }

    public static function error(
        string $message,
        string $errorCode = 'API_ERROR',
        int $status = 400,
        array $errors = []
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => $errors,
            'status' => $status,
        ];
    }
}
```

### File: `app/Exceptions/Handler.php` (Update)
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
    // Log error details
    Log::error('API Exception', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'user_id' => auth()->id(),
        'url' => $request->fullUrl(),
        'method' => $request->method(),
    ]);

    if ($exception instanceof ApiException) {
        return response()->json(
            ApiResponseService::error(
                $exception->getMessage(),
                $exception->getErrorCode(),
                $exception->getStatusCode(),
                $exception->getErrors()
            ),
            $exception->getStatusCode()
        );
    }

    // Generic error for production
    $message = app()->environment('production')
        ? 'An error occurred'
        : $exception->getMessage();

    return response()->json(
        ApiResponseService::error($message, 'INTERNAL_ERROR', 500),
        500
    );
}
```

### Controller Example:
```php
public function store(CreateUserRequest $request)
{
    try {
        $user = $this->userService->createUser($request->validated());
        return response()->json(
            ApiResponseService::success($user, 'User created successfully'),
            201
        );
    } catch (ValidationException $e) {
        throw new ApiException('Validation failed', 422, 'VALIDATION_ERROR', $e->errors());
    } catch (Exception $e) {
        Log::error('User creation failed', ['error' => $e->getMessage()]);
        throw new ApiException('Failed to create user', 500);
    }
}
```

## Verification Steps

1. **Error Response Consistency:**
   ```bash
   # Test various error scenarios
   curl -X POST /api/test -d "invalid=data"
   ```

2. **Logging Verification:**
   - Check logs contain proper context
   - Verify sensitive data not logged

3. **API Response Format:**
   - Verify all responses follow standard format
   - Check error codes are consistent

## Rollback Plan

If error handling changes break clients:
1. Keep old format temporarily
2. Add version headers for API versioning
3. Gradually migrate responses

## Testing Requirements

- [ ] Error responses consistent
- [ ] Proper logging implemented
- [ ] Client compatibility maintained
- [ ] Error scenarios covered

## Documentation Updates

- Document error response format
- Update API error codes
- Mark as completed in master work plan

## Completion Criteria

- [ ] Error handling standardized
- [ ] Consistent response format
- [ ] Proper logging implemented
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #30: Missing API Documentation

**Status:** Pending
**Priority:** Nice-to-Have
**Estimated Effort:** 4-6 hours
**Assigned:** Unassigned

## Problem Description

No OpenAPI/Swagger documentation for API endpoints, making external integrations difficult.

**Location:** `routes/api.php`

## Impact Assessment

- **Severity:** Nice-to-Have - Integration difficulty
- **Scope:** API discoverability
- **Risk:** Low - Affects third-party integrations
- **Dependencies:** API routes, controllers

## Solution Overview

Implement Laravel API documentation package with comprehensive endpoint documentation.

## Implementation Steps

### Phase 1: Documentation Tool Setup (1 hour)
1. Install API documentation package
2. Configure documentation generation
3. Set up basic structure

### Phase 2: Document Core Endpoints (2-3 hours)
1. Document authentication endpoints
2. Document user management endpoints
3. Document voice routing endpoints
4. Add request/response examples

### Phase 3: Add Interactive Features (1 hour)
1. Configure authentication in docs
2. Add try-it functionality
3. Set up documentation hosting

### Phase 4: Documentation Maintenance (30 minutes)
1. Add to CI/CD pipeline
2. Document update process
3. Create contribution guidelines

## Code Changes

### File: `composer.json` (Add dependency)
```json
{
    "require-dev": {
        "darkaonline/l5-swagger": "^8.0"
    }
}
```

### File: `config/l5-swagger.php` (Create/Update)
```php
<?php

return [
    'api' => [
        'title' => 'OpBX API',
        'version' => '1.0.0',
        'description' => 'Open Source Business PBX API',
    ],
    'paths' => [
        'docs' => 'api/documentation',
        'assets' => 'api/docs/assets',
        'openapi' => 'api/docs/openapi.yaml',
    ],
    'security' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
        ],
    ],
];
```

### File: `routes/api.php` (Add annotations)
```php
/**
 * @OA\Post(
 *     path="/api/auth/login",
 *     summary="User login",
 *     tags={"Authentication"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email","password"},
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Login successful",
 *         @OA\JsonContent(
 *             @OA\Property(property="user", ref="#/components/schemas/User"),
 *             @OA\Property(property="token", type="string")
 *         )
 *     )
 * )
 */
Route::post('/auth/login', [AuthController::class, 'login']);
```

### New File: `app/Http/Resources/UserResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="User",
 *     type="object",
 *     title="User",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="email", type="string", format="email"),
 *     @OA\Property(property="role", type="string", enum={"owner", "admin", "user", "reporter"}),
 *     @OA\Property(property="status", type="string", enum={"active", "suspended", "deleted"})
 * )
 */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
```

### File: `routes/web.php` (Add documentation route)
```php
Route::get('/api/documentation', function () {
    return view('l5-swagger::index');
});
```

## Verification Steps

1. **Documentation Generation:**
   ```bash
   php artisan l5-swagger:generate
   ```

2. **Documentation Access:**
   - Visit `/api/documentation`
   - Verify interactive interface works
   - Test API calls through docs

3. **Content Verification:**
   - Check all endpoints documented
   - Verify request/response examples
   - Test authentication in docs

## Rollback Plan

If documentation causes issues:
1. Make documentation optional
2. Host externally if needed
3. Keep simple endpoint list as fallback

## Testing Requirements

- [ ] Documentation generates successfully
- [ ] Interactive interface works
- [ ] All endpoints documented
- [ ] Examples are accurate

## Documentation Updates

- Create API documentation guide
- Update integration documentation
- Mark as completed in master work plan

## Completion Criteria

- [ ] API documentation generated
- [ ] Interactive docs accessible
- [ ] Comprehensive endpoint coverage
- [ ] Code reviewed and approved

---

**Estimated Completion:** 4-6 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________