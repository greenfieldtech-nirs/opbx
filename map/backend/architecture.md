# Backend Architecture Patterns

## Overview

OpBX backend follows domain-driven design principles with clear separation between Control Plane (configuration) and Execution Plane (real-time call processing). The architecture emphasizes scalability, security, and maintainability.

## Core Architectural Principles

### 1. Control vs Execution Plane Separation

#### Control Plane (CRUD Configuration)
- **Purpose**: Administrative configuration and management
- **Data Source**: MySQL (durable, transactional)
- **Authentication**: Sanctum tokens via API
- **Response Time**: Standard web response times
- **Consistency**: Strong consistency required

**Components**:
- Laravel Controllers (API)
- Eloquent Models
- Database migrations
- Form validation
- RBAC authorization

#### Execution Plane (Real-time Call Processing)
- **Purpose**: Live call routing and state management
- **Data Source**: Redis (ephemeral, high-performance)
- **Authentication**: Bearer tokens via headers
- **Response Time**: Sub-second (telephony requirements)
- **Consistency**: Eventual consistency acceptable

**Components**:
- Webhook controllers
- Voice routing controllers
- State machines
- CXML generators
- Circuit breakers

### 2. Multi-Tenant Architecture

#### Organization Scoping
All models include global scopes for tenant isolation:

```php
// In base model
protected static function booted()
{
    static::addGlobalScope(new OrganizationScope());
}

// Usage in queries
User::all(); // Automatically scoped to current organization
```

#### Tenant Context
Organization context established through:
- Authenticated user session
- Request headers
- URL parameters

#### Data Isolation
- Foreign key constraints enforce ownership
- Database-level row security
- Application-level authorization checks

### 3. State Management Strategy

#### MySQL (Durable State)
- Organizations, users, extensions
- Routing configurations
- Call history and CDR data
- Audit logs

#### Redis (Ephemeral State)
- Call routing caches
- Idempotency keys (TTL-based)
- Distributed locks
- Real-time presence data
- Session state

#### State Synchronization
- Database changes invalidate Redis caches
- Observers handle cache invalidation
- Circuit breaker prevents cascade failures

### 4. Authentication & Authorization

#### Dual Authentication System

**API Authentication (Sanctum)**:
```php
// Sanctum tokens for web interface
Route::middleware(['auth:sanctum'])->group(function () {
    // Protected API routes
});
```

**Voice Authentication (Bearer)**:
```php
// Bearer tokens for telephony
Route::middleware(['auth:bearer'])->group(function () {
    // Voice routing routes
});
```

#### Role-Based Access Control
```php
enum Role: string
{
    case OWNER = 'owner';      // Full access
    case ADMIN = 'admin';      // User management
    case AGENT = 'agent';      // Basic features
    case USER = 'user';        // Read-only
}
```

#### Permission Checking
Policies enforce business rules:
```php
class UserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->organization_id === $target->organization_id
            && $user->role->canManage($target->role);
    }
}
```

### 5. Error Handling & Resilience

#### Circuit Breaker Pattern
```php
class CircuitBreakerService
{
    public function call(Callable $operation)
    {
        if ($this->isOpen()) {
            throw new CircuitBreakerOpenException();
        }
        
        try {
            $result = $operation();
            $this->recordSuccess();
            return $result;
        } catch (Exception $e) {
            $this->recordFailure();
            throw $e;
        }
    }
}
```

#### Graceful Degradation
- Redis fallback to database
- External service timeouts
- Default routing behaviors
- Error logging with correlation IDs

#### Structured Error Responses
```php
// API errors (JSON)
{
    "message": "Validation failed",
    "errors": {
        "extension_number": ["Extension already exists"]
    },
    "code": "VALIDATION_ERROR"
}

// Voice errors (CXML)
<Response>
  <Say>System error, please try again</Say>
  <Hangup/>
</Response>
```

### 6. Caching Strategy

#### Multi-Level Caching

**Application Cache (Redis)**:
- Extension lookups by number
- DID routing configurations
- Ring group member lists
- Business hours schedules

**Database Query Cache**:
- Complex joins and aggregations
- Frequently accessed reference data

#### Cache Invalidation
Observers handle automatic invalidation:
```php
class ExtensionCacheObserver
{
    public function updated(Extension $extension)
    {
        Cache::forget("extension:{$extension->organization_id}:{$extension->extension_number}");
    }
}
```

### 7. Idempotency & Race Condition Prevention

#### Webhook Idempotency
```php
public function handleWebhook(Request $request)
{
    $idempotencyKey = $request->header('X-Idempotency-Key');
    
    if (Redis::exists("idem:webhook:{$idempotencyKey}")) {
        return response()->json(['status' => 'processed']);
    }
    
    Redis::setex("idem:webhook:{$idempotencyKey}", 3600, 'processed');
    
    // Process webhook
}
```

#### Distributed Locking
```php
$lock = Redis::lock("call:{$callId}", 30);
try {
    $lock->acquire();
    // Critical section
} finally {
    $lock->release();
}
```

### 8. Background Processing

#### Queue Architecture
```php
// Queue configuration
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'default',
    'retry_after' => 90,
],

// Job dispatching
ProcessCallRecording::dispatch($callId)->onQueue('recordings');
```

#### Worker Processes
- Call recording processing
- CDR data aggregation
- Cache warming
- Notification delivery

### 9. Logging & Observability

#### Structured Logging
```php
Log::info('Call routed', [
    'call_id' => $callId,
    'organization_id' => $orgId,
    'routing_type' => 'extension',
    'target' => $extension->extension_number,
    'timestamp' => now(),
]);
```

#### Correlation IDs
All log entries include call_id or request_id for tracing:
```php
// Middleware adds correlation ID
public function handle($request, $next)
{
    $correlationId = $request->header('X-Correlation-ID', Str::uuid());
    Log::withContext(['correlation_id' => $correlationId]);
    
    return $next($request);
}
```

### 10. Database Design Patterns

#### Soft Deletes
Preserve referential integrity while allowing recovery:
```php
class User extends Model
{
    use SoftDeletes;
    
    protected $dates = ['deleted_at'];
}
```

#### Polymorphic Relationships
Flexible routing destinations:
```php
// DID can route to different types
class DidNumber extends Model
{
    public function routingTarget()
    {
        return $this->morphTo();
    }
}
```

#### JSON Columns
Extensible configuration storage:
```php
// Organization settings
$table->json('settings')->nullable();

// Querying JSON
Organization::where('settings->theme', 'dark')->get();
```

### 11. Security Architecture

#### Input Validation
Multi-layer validation:
```php
// Form requests
class StoreUserRequest extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email|unique:users,email,NULL,id,organization_id,' . $this->organization->id,
            'role' => 'required|in:owner,admin,agent,user',
        ];
    }
}

// Service layer validation
class UserService
{
    public function create(array $data)
    {
        $this->validateBusinessRules($data);
        // ...
    }
}
```

#### Data Sanitization
Automatic sanitization of user inputs:
```php
class LogSanitizer
{
    public static function sanitize($data)
    {
        // Remove sensitive fields
        unset($data['password'], $data['api_key']);
        return $data;
    }
}
```

### 12. Testing Strategy

#### Test Organization
```
tests/
├── Feature/          # Integration tests
├── Unit/            # Unit tests
├── Mocks/           # Test doubles
└── TestCase.php     # Base test case
```

#### Test Types
- **Unit Tests**: Service layer business logic
- **Feature Tests**: API endpoints and workflows
- **Integration Tests**: External service interactions
- **Database Tests**: Migration and seeding verification

#### Testing Patterns
```php
class VoiceRoutingTest extends TestCase
{
    use DatabaseTransactions;
    
    public function test_inbound_call_routing()
    {
        // Arrange
        $did = DidNumber::factory()->create(['routing_type' => 'extension']);
        
        // Act
        $response = $this->postJson('/voice/route', [
            'to' => $did->number,
            'from' => '+1234567890'
        ]);
        
        // Assert
        $response->assertStatus(200)
                ->assertSee('<Dial>', false);
    }
}
```

This architecture provides a robust, scalable foundation for the PBX system with clear separation of concerns, comprehensive error handling, and strong security guarantees.