# Laravel Backend Structure

## Overview

The OpBX Laravel application follows a modern PHP architecture with domain-driven design principles, comprehensive service layers, and enterprise-grade security. The codebase is organized into clear layers with proper separation of concerns.

## Root Directory Structure

```
opbx/
├── app/                          # Application code
│   ├── Console/                  # Artisan commands
│   ├── Enums/                    # PHP 8.1+ enums
│   ├── Events/                   # Laravel events
│   ├── Exceptions/               # Custom exceptions
│   ├── Http/                     # HTTP layer
│   │   ├── Controllers/          # API controllers
│   │   ├── Middleware/           # HTTP middleware
│   │   ├── Requests/             # Form validation
│   │   └── Resources/            # API transformers
│   ├── Jobs/                     # Queue jobs
│   ├── Models/                   # Eloquent models
│   ├── Policies/                 # Authorization policies
│   ├── Providers/                # Service providers
│   ├── Scopes/                   # Global query scopes
│   ├── Services/                 # Business logic services
│   └── Observers/                # Model observers
├── bootstrap/                    # Laravel bootstrap
├── config/                       # Configuration files
├── database/                     # Migrations, seeders, factories
│   ├── factories/               # Model factories
│   ├── migrations/              # Database migrations
│   └── seeders/                 # Database seeders
├── public/                       # Public assets
├── resources/                    # Views, assets
├── routes/                       # Route definitions
│   ├── api.php                  # API routes
│   ├── channels.php             # Broadcasting channels
│   ├── console.php              # Console routes
│   └── web.php                  # Web routes
├── storage/                      # File storage
├── tests/                        # Test suite
│   ├── Feature/                 # Feature tests
│   ├── Unit/                    # Unit tests
│   └── ...                      # Test utilities
├── vendor/                       # Composer dependencies
├── artisan                      # Laravel CLI
├── composer.json                # PHP dependencies
├── phpunit.xml                  # Test configuration
└── ...                          # Laravel config files
```

## Application Layer (app/)

### Console Commands

```
app/Console/
├── Commands/
│   ├── SyncCloudonixSubscribers.php    # Sync SIP accounts
│   ├── ProcessStaleCallLogs.php       # Clean up old calls
│   ├── GenerateCloudonixKeys.php      # API key management
│   └── UpdateCallStatistics.php       # Analytics updates
```

**Key Commands:**
- **SyncCloudonixSubscribers**: Syncs extension data with Cloudonix API
- **ProcessStaleCallLogs**: Archives old call records
- **GenerateCloudonixKeys**: Creates API authentication keys

### Enums

```
app/Enums/
├── UserRole.php                     # RBAC roles
├── ExtensionType.php                # Extension purposes
├── CallStatus.php                   # Call state machine
├── RingStrategy.php                 # Ring group distribution
├── CallDirection.php                # Inbound/outbound
├── DayOfWeek.php                    # Business hours
├── RoutingType.php                  # DID routing options
├── IvrDestinationType.php           # IVR menu actions
└── BusinessHoursType.php            # Time period types
```

**Design Benefits:**
- Type safety with PHP 8.1 enums
- Database consistency
- IDE autocompletion
- Validation enforcement

### Events

```
app/Events/
├── CallInitiated.php               # New inbound call
├── CallAnswered.php                # Call answered event
├── CallEnded.php                   # Call completion
├── ExtensionCreated.php            # Extension lifecycle
├── RingGroupUpdated.php            # Configuration changes
└── CloudonixSettingsChanged.php    # API config updates
```

**Usage Pattern:**
```php
// Dispatch event after model changes
CallInitiated::dispatch($callLog);

// Listen in EventServiceProvider
protected $listen = [
    CallInitiated::class => [
        SendCallNotification::class,
        UpdateRealTimePresence::class,
    ],
];
```

### Exceptions

```
app/Exceptions/
├── Handler.php                     # Global exception handler
├── WebhookAuthenticationException.php
├── RoutingConfigurationException.php
├── CloudonixApiException.php
└── TenantIsolationException.php
```

**Exception Hierarchy:**
- **WebhookAuthenticationException**: Invalid webhook signatures
- **RoutingConfigurationException**: Invalid PBX configuration
- **CloudonixApiException**: External API failures
- **TenantIsolationException**: Multi-tenancy violations

### HTTP Layer

#### Controllers

```
app/Http/Controllers/
├── Api/
│   ├── V1/
│   │   ├── AuthController.php           # Authentication
│   │   ├── UserController.php           # User management
│   │   ├── ExtensionController.php      # SIP extensions
│   │   ├── RingGroupController.php      # Call distribution
│   │   ├── DidController.php            # Phone numbers
│   │   ├── IvrMenuController.php        # IVR systems
│   │   ├── BusinessHoursController.php  # Time routing
│   │   ├── CallLogController.php        # Call history
│   │   ├── RecordingController.php      # Call recordings
│   │   └── SettingController.php        # Organization settings
├── Voice/
│   ├── RouteController.php             # Call routing
│   ├── IvrInputController.php          # DTMF processing
│   └── RingGroupCallbackController.php # Ring group handling
└── Webhook/
    └── CloudonixController.php          # Status webhooks
```

**Controller Patterns:**
- **Resource Controllers**: Standard CRUD operations
- **Single Action Controllers**: Focused webhook handling
- **API Resource Controllers**: JSON API responses

#### Middleware

```
app/Http/Middleware/
├── EnsureOrganization.php              # Tenant routing
├── EnsureWebhookIdempotency.php        # Duplicate prevention
├── VerifyVoiceWebhookAuth.php          # Voice webhook auth
├── VerifyCloudonixSignature.php        # Status webhook auth
├── EnsureUserRole.php                  # RBAC enforcement
└── RateLimitOrganization.php           # Per-org rate limiting
```

**Security Middleware Stack:**
```php
// API routes
'auth:sanctum',
EnsureOrganization::class,
EnsureUserRole::class,
RateLimitOrganization::class,

// Webhook routes
VerifyVoiceWebhookAuth::class,
EnsureWebhookIdempotency::class,
```

#### Form Requests

```
app/Http/Requests/
├── CreateUserRequest.php
├── UpdateExtensionRequest.php
├── CreateRingGroupRequest.php
├── UpdateDidRequest.php
├── CreateIvrMenuRequest.php
├── UpdateBusinessHoursRequest.php
└── CloudonixSettingsRequest.php
```

**Validation Features:**
- **Authorization**: `authorize()` method for permissions
- **Preparation**: `prepareForValidation()` for data transformation
- **Custom Rules**: Business logic validation rules

#### API Resources

```
app/Http/Resources/
├── UserResource.php
├── ExtensionResource.php
├── RingGroupResource.php
├── DidResource.php
├── CallLogResource.php
├── CallDetailRecordResource.php
└── IvrMenuResource.php
```

**Resource Features:**
- **Conditional Fields**: Hide sensitive data based on permissions
- **Relationships**: Include related models
- **Pagination**: Automatic pagination metadata

### Jobs (Queue Processing)

```
app/Jobs/
├── ProcessInboundCallJob.php          # Initial call logging
├── UpdateCallStatusJob.php            # State transitions
├── ProcessCDRJob.php                  # Call completion
├── ProcessRecordingUpload.php         # File processing
├── SyncCloudonixSubscriberJob.php     # API synchronization
└── SendCallNotificationJob.php        # External notifications
```

**Queue Configuration:**
```php
// config/queue.php
'default' => env('QUEUE_CONNECTION', 'redis'),
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
    ],
],
```

### Models

```
app/Models/
├── User.php                          # User accounts
├── Organization.php                  # Multi-tenant root
├── Extension.php                     # SIP extensions
├── DidNumber.php                     # Phone numbers
├── RingGroup.php                     # Distribution groups
├── RingGroupMember.php               # Group membership
├── CallLog.php                       # Call history
├── CallDetailRecord.php              # Detailed CDRs
├── IvrMenu.php                       # IVR configurations
├── IvrMenuOption.php                 # IVR choices
├── BusinessHoursSchedule.php         # Time schedules
├── BusinessHoursRule.php             # Weekly rules
├── BusinessHoursDestination.php      # Routing destinations
├── ConferenceRoom.php                # Audio conferencing
├── CloudonixSetting.php              # API configuration
├── OutboundWhitelist.php             # Calling permissions
├── Recording.php                     # Call recordings
└── SessionUpdate.php                 # Real-time monitoring
```

**Model Features:**
- **Global Scopes**: Automatic tenant isolation
- **Observers**: Cache invalidation, audit logging
- **Relationships**: Proper foreign key constraints
- **Accessors/Mutators**: Data transformation
- **Soft Deletes**: Safe record removal

### Policies (Authorization)

```
app/Policies/
├── UserPolicy.php                    # User management permissions
├── ExtensionPolicy.php               # Extension CRUD
├── RingGroupPolicy.php               # Group management
├── DidPolicy.php                     # Number administration
├── IvrMenuPolicy.php                 # IVR configuration
├── BusinessHoursPolicy.php           # Schedule management
├── CallLogPolicy.php                 # History access
├── RecordingPolicy.php               # Recording permissions
└── CloudonixSettingPolicy.php        # API settings
```

**Policy Methods:**
```php
public function viewAny(User $user): bool
public function view(User $user, Model $model): bool
public function create(User $user): bool
public function update(User $user, Model $model): bool
public function delete(User $user, Model $model): bool
```

### Service Providers

```
app/Providers/
├── AppServiceProvider.php            # Core bindings
├── AuthServiceProvider.php           # Gates/policies
├── BroadcastServiceProvider.php      # WebSocket config
├── EventServiceProvider.php          # Event listeners
└── RouteServiceProvider.php          # Route registration
```

### Scopes

```
app/Scopes/
├── OrganizationScope.php             # Tenant isolation
├── ActiveScope.php                   # Active records only
└── TenantScope.php                   # Generic tenant scoping
```

**OrganizationScope Implementation:**
```php
public function apply(Builder $builder, Model $model): void
{
    $organizationId = $this->getOrganizationId();
    if ($organizationId !== null) {
        $builder->where($model->getTable() . '.organization_id', $organizationId);
    } else {
        // Security: Force zero results when unauthenticated
        $builder->whereRaw('1 = 0');
    }
}
```

### Services (Business Logic)

```
app/Services/
├── CallRouting/
│   ├── CallRoutingService.php        # Main routing coordinator
│   ├── ExtensionRoutingService.php   # Direct extension logic
│   ├── RingGroupRoutingService.php   # Distribution logic
│   ├── BusinessHoursRoutingService.php # Time-based routing
│   └── IvrRoutingService.php         # IVR menu logic
├── CallStateManager.php              # State machine
├── CloudonixClient.php               # API integration
├── CxmlBuilder.php                   # XML generation
├── VoiceRoutingManager.php           # Unified routing
├── CircuitBreaker.php                # Resilience pattern
└── CacheInvalidationService.php      # Cache management
```

**Service Architecture:**
- **Dependency Injection**: Constructor injection
- **Interface Segregation**: Focused service interfaces
- **Single Responsibility**: Each service has one purpose
- **Testability**: Mockable dependencies

### Observers

```
app/Observers/
├── ExtensionObserver.php             # Cache invalidation
├── RingGroupObserver.php             # Membership updates
├── DidObserver.php                   # Routing cache clearing
├── BusinessHoursObserver.php         # Schedule changes
└── CloudonixSettingsObserver.php     # API config updates
```

## Configuration Layer

### Key Configuration Files

```
config/
├── app.php                          # Application settings
├── auth.php                         # Authentication config
├── broadcasting.php                 # WebSocket settings
├── cache.php                        # Cache configuration
├── database.php                     # Database connections
├── filesystems.php                  # File storage
├── logging.php                      # Logging channels
├── queue.php                        # Job processing
├── sanctum.php                      # API authentication
└── cloudonix.php                    # PBX-specific settings
```

### Environment Configuration

```bash
# Application
APP_NAME=OpBX
APP_ENV=production
APP_KEY=base64:generated_key
APP_DEBUG=false

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=opbx
DB_USERNAME=opbx
DB_PASSWORD=secure_password

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cloudonix
CLOUDONIX_API_BASE_URL=https://api.cloudonix.io
CLOUDONIX_WEBHOOK_SECRET=webhook_secret

# Broadcasting
BROADCAST_DRIVER=redis
PUSHER_APP_ID=opbx
PUSHER_APP_KEY=app_key
PUSHER_APP_SECRET=app_secret
PUSHER_HOST=soketi
PUSHER_PORT=6001
```

## Testing Structure

```
tests/
├── Feature/
│   ├── Api/
│   │   ├── AuthTest.php
│   │   ├── ExtensionTest.php
│   │   ├── RingGroupTest.php
│   │   └── WebhookTest.php
│   └── Http/
├── Unit/
│   ├── Services/
│   │   ├── CallRoutingServiceTest.php
│   │   ├── CxmlBuilderTest.php
│   │   └── CloudonixClientTest.php
│   └── Models/
├── TestCase.php
└── CreatesApplication.php
```

## Key Design Patterns

### 1. Repository Pattern (Implicit)
- Services act as repositories with business logic
- Clean separation between data access and business rules

### 2. Service Layer Pattern
- Business logic encapsulated in service classes
- Dependency injection for testability
- Single responsibility principle

### 3. Observer Pattern
- Model events trigger cache invalidation
- Loose coupling between models and side effects

### 4. Strategy Pattern
- Routing strategies (extension, ring group, business hours)
- Pluggable routing logic

### 5. Circuit Breaker Pattern
- External API resilience
- Automatic failure recovery

## Performance Optimizations

### Database
- **Composite Indexes**: Optimized for common queries
- **Connection Pooling**: Efficient database connections
- **Query Optimization**: N+1 query prevention

### Caching
- **Multi-Level Caching**: OPcache + Redis
- **Intelligent TTL**: Context-aware expiration
- **Cache Warming**: Proactive cache population

### Background Processing
- **Queue-Based**: Non-blocking operations
- **Job Batching**: Efficient bulk processing
- **Retry Logic**: Automatic failure recovery

## Security Implementation

### Authentication
- **Laravel Sanctum**: Dual auth modes (cookie + token)
- **Rate Limiting**: Brute force protection
- **Session Security**: Secure cookie configuration

### Authorization
- **Policy-Based**: Fine-grained permissions
- **RBAC**: Hierarchical role system
- **Multi-Tenant**: Organization isolation

### Data Protection
- **Encryption**: Sensitive data encryption
- **Sanitization**: Input/output filtering
- **Audit Logging**: Security event tracking

This Laravel structure provides a robust, scalable, and maintainable foundation for the OpBX PBX system with enterprise-grade security, performance optimizations, and clean architectural patterns.