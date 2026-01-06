# Backend Services Layer

## Overview

OpBX uses a comprehensive service layer to encapsulate business logic, external integrations, and infrastructure concerns. Services are organized by responsibility and follow dependency injection patterns.

## Call Routing Services

### CallRoutingService
**Location**: `app/Services/CallRoutingService.php`

Core routing logic for inbound calls.

**Key Methods**:
- `resolveDidRouting(DidNumber $did)`: Determines routing destination based on DID configuration
- `getExtensionByNumber(string $number, int $organizationId)`: Extension lookup with caching
- `validateRoutingTarget($target, $type)`: Validates routing destinations
- `getRoutingPriority(array $routes)`: Determines execution order for multiple routes

**Features**:
- DID-based routing resolution
- Extension availability checking
- Business hours evaluation
- Ring group member selection

### VoiceRoutingManager
**Location**: `app/Services/VoiceRoutingManager.php`

Orchestrates complex routing scenarios with caching.

**Key Methods**:
- `routeInboundCall(string $did, array $context)`: Main inbound routing entry point
- `handleRingGroupRouting(RingGroup $group, array $context)`: Ring group distribution logic
- `evaluateBusinessHours(BusinessHours $rule)`: Time-based routing decisions
- `generateCxmlResponse(array $routing)`: Creates Cloudonix XML responses

**Features**:
- Strategy pattern for different routing types
- Redis caching for performance
- State machine integration
- Error handling and fallbacks

### VoiceRoutingCacheService
**Location**: `app/Services/VoiceRoutingCacheService.php`

Caching layer for routing lookups to minimize database queries.

**Cache Keys**:
- `extension:{org_id}:{number}`: Extension data by number
- `did_routing:{org_id}:{number}`: DID routing configuration
- `ring_group_members:{group_id}`: Ring group member lists
- `business_hours:{rule_id}`: Business hours schedules

**Methods**:
- `getCachedExtension($number, $orgId)`: Cached extension lookup
- `invalidateExtensionCache($extensionId)`: Cache invalidation
- `warmRoutingCaches()`: Pre-populate critical caches

## Business Logic Services

### IvrStateService
**Location**: `app/Services/IvrStateService.php`

Manages IVR conversation state and digit processing.

**Key Methods**:
- `processIvrInput(string $callId, string $digits, int $orgId)`: Process DTMF input
- `getCurrentMenu(string $callId)`: Retrieve current IVR state
- `advanceToNextMenu(string $callId, $menuOption)`: State transitions
- `handleTimeout(string $callId)`: Timeout handling

**State Management**:
- Redis-based state storage
- TTL-based cleanup
- Idempotent operations

### IvrMenuService
**Location**: `app/Services/IvrMenuService.php`

IVR menu operations and validation.

**Key Methods**:
- `validateMenuStructure(IvrMenu $menu)`: Menu configuration validation
- `getMenuOptions(IvrMenu $menu)`: Retrieve active options
- `resolveOptionTarget(IvrMenuOption $option)`: Destination resolution
- `generateVoicePrompt(IvrMenu $menu)`: TTS prompt generation

### RoutingSentryService
**Location**: `app/Services/RoutingSentryService.php`

Security filtering for call routing operations.

**Key Methods**:
- `validateOrganizationAccess($resource, $orgId)`: Tenant isolation validation
- `checkRoutingPermissions($user, $target)`: Permission checking
- `auditRoutingDecision($callId, $decision)`: Audit logging

## Infrastructure Services

### ResilientCacheService
**Location**: `app/Services/ResilientCacheService.php`

Redis wrapper with database fallback for high availability.

**Key Methods**:
- `get($key, $default = null)`: Get with DB fallback
- `set($key, $value, $ttl = null)`: Set with error handling
- `lock($key, $timeout = 30)`: Distributed locking
- `remember($key, $ttl, $callback)`: Cache-aside pattern

**Features**:
- Automatic fallback to database
- Connection pooling
- Circuit breaker pattern integration
- Lock timeout handling

### CxmlBuilder
**Location**: `app/Services/CxmlBuilder.php`

Generates Cloudonix XML responses for call control.

**Key Methods**:
- `createDialResponse($destination)`: Simple dial commands
- `createRingGroupResponse($members, $strategy)`: Ring group handling
- `createIvrResponse($menu)`: IVR menu presentation
- `createConferenceResponse($room)`: Conference bridging

**XML Generation**:
```xml
<Response>
  <Dial>
    <Number>+1234567890</Number>
  </Dial>
</Response>
```

### PasswordGenerator
**Location**: `app/Services/PasswordGenerator.php`

Secure password generation for SIP extensions.

**Key Methods**:
- `generateSipPassword()`: Cryptographically secure passwords
- `validatePasswordStrength($password)`: Security validation
- `hashPassword($password)`: Secure hashing

## External Integration Services

### CloudonixClient (Conceptual)
**Location**: `app/Services/CloudonixClient/`

REST API client for Cloudonix platform integration.

**Key Components**:
- **BaseClient**: HTTP client with authentication
- **DomainClient**: Domain management operations
- **ExtensionClient**: SIP endpoint synchronization
- **CallClient**: Call control and monitoring

**Authentication**:
- Bearer token authentication
- Automatic token refresh
- Request/response logging

### CircuitBreaker
**Location**: `app/Services/CircuitBreaker/`

Failure handling for external service calls.

**Key Classes**:
- **CircuitBreakerService**: Main circuit breaker implementation
- **CircuitBreakerState**: Open/closed/half-open states
- **FailureThreshold**: Configurable failure detection

**States**:
- **Closed**: Normal operation
- **Open**: Failing fast (no requests)
- **Half-Open**: Testing recovery

## Security Services

### Security Checks
**Location**: `app/Services/Security/Checks/`

Comprehensive security validation layer.

**Key Services**:
- **InputSanitizer**: XSS and injection prevention
- **RateLimitChecker**: API rate limiting
- **AuditLogger**: Security event logging
- **EncryptionService**: Data encryption/decryption

### Logging Services
**Location**: `app/Services/Logging/`

Structured logging with correlation IDs.

**Key Services**:
- **CallLogger**: Call-specific logging
- **SecurityLogger**: Security events
- **PerformanceLogger**: Response times and metrics
- **LogSanitizer**: Sensitive data removal

## Service Organization Patterns

### Dependency Injection
All services use Laravel's service container for dependency injection:

```php
public function __construct(
    ResilientCacheService $cache,
    CxmlBuilder $cxmlBuilder,
    CallRoutingService $routing
) {
    $this->cache = $cache;
    // ...
}
```

### Interface Segregation
Services implement focused interfaces:

```php
interface RoutingServiceInterface
{
    public function routeCall($context): RoutingResult;
}

interface CacheServiceInterface
{
    public function get($key);
    public function set($key, $value, $ttl = null);
}
```

### Service Registration
Services registered in `AppServiceProvider`:

```php
public function register()
{
    $this->app->singleton(CacheServiceInterface::class, ResilientCacheService::class);
    $this->app->singleton(RoutingServiceInterface::class, VoiceRoutingManager::class);
}
```

### Testing
Services include comprehensive test suites:
- Unit tests for business logic
- Integration tests for external APIs
- Mock services for isolated testing

See `backend/controllers.md` for how controllers use these services, and `backend/routes.md` for the API endpoints that trigger service execution.