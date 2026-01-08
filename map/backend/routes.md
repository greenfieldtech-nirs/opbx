# Backend Routes & API Endpoints

## Overview

OpBX defines routes in multiple files based on functionality. Routes are organized by authentication method and responsibility.

## API Routes (Control Plane)
**File**: `routes/api.php`

CRUD operations for PBX configuration. Requires Sanctum authentication.

### Authentication Routes
```
POST   /api/v1/auth/login              → AuthController@login
```
**Middleware**: `api`
**Purpose**: Sanctum token authentication

### User Management
```
GET    /api/v1/users                   → UsersController@index
POST   /api/v1/users                   → UsersController@store
GET    /api/v1/users/{id}              → UsersController@show
PUT    /api/v1/users/{id}              → UsersController@update
DELETE /api/v1/users/{id}              → UsersController@destroy
POST   /api/v1/users/{id}/restore      → UsersController@restore
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`
**Features**: Role-based permissions, organization scoping

### Extension Management
```
GET    /api/v1/extensions              → ExtensionsController@index
POST   /api/v1/extensions              → ExtensionsController@store
GET    /api/v1/extensions/{id}         → ExtensionsController@show
PUT    /api/v1/extensions/{id}         → ExtensionsController@update
DELETE /api/v1/extensions/{id}         → ExtensionsController@destroy
POST   /api/v1/extensions/{id}/regenerate-password → ExtensionsController@regeneratePassword
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### Ring Group Management
```
GET    /api/v1/ring-groups             → RingGroupsController@index
POST   /api/v1/ring-groups             → RingGroupsController@store
GET    /api/v1/ring-groups/{id}        → RingGroupsController@show
PUT    /api/v1/ring-groups/{id}        → RingGroupsController@update
DELETE /api/v1/ring-groups/{id}        → RingGroupsController@destroy
POST   /api/v1/ring-groups/{id}/members → RingGroupsController@addMember
DELETE /api/v1/ring-groups/{id}/members/{memberId} → RingGroupsController@removeMember
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### Business Hours
```
GET    /api/v1/business-hours          → BusinessHoursController@index
POST   /api/v1/business-hours          → BusinessHoursController@store
GET    /api/v1/business-hours/{id}     → BusinessHoursController@show
PUT    /api/v1/business-hours/{id}     → BusinessHoursController@update
DELETE /api/v1/business-hours/{id}     → BusinessHoursController@destroy
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### Phone Numbers (DIDs)
```
GET    /api/v1/phone-numbers           → PhoneNumbersController@index
POST   /api/v1/phone-numbers           → PhoneNumbersController@store
GET    /api/v1/phone-numbers/{id}      → PhoneNumbersController@show
PUT    /api/v1/phone-numbers/{id}      → PhoneNumbersController@update
DELETE /api/v1/phone-numbers/{id}      → PhoneNumbersController@destroy
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### Call History
```
GET    /api/v1/call-logs               → CallLogController@index
GET    /api/v1/call-logs/{id}          → CallLogController@show
GET    /api/v1/call-detail-records     → CallDetailRecordController@index
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`
**Features**: Date filtering, pagination, search

### Conference Rooms
```
GET    /api/v1/conference-rooms        → ConferenceRoomController@index
POST   /api/v1/conference-rooms        → ConferenceRoomController@store
PUT    /api/v1/conference-rooms/{id}   → ConferenceRoomController@update
DELETE /api/v1/conference-rooms/{id}   → ConferenceRoomController@destroy
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### IVR Menus
```
GET    /api/v1/ivr-menus               → IvrMenuController@index
POST   /api/v1/ivr-menus               → IvrMenuController@store
PUT    /api/v1/ivr-menus/{id}          → IvrMenuController@update
DELETE /api/v1/ivr-menus/{id}          → IvrMenuController@destroy
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

### Profile & Settings
```
GET    /api/v1/profile                 → ProfileController@show
PUT    /api/v1/profile                 → ProfileController@update
GET    /api/v1/settings                → SettingsController@show (Owner only)
PUT    /api/v1/settings                → SettingsController@update (Owner only)
```
**Middleware**: `auth:sanctum`, `EnsureTenantScope`

## Webhook Routes (Execution Plane)
**File**: `routes/webhooks.php`

Real-time Cloudonix webhook processing with idempotency.

### Voice Routing Webhooks
```
POST   /webhooks/cloudonix/call-initiated → CloudonixWebhookController@callInitiated
POST   /webhooks/cloudonix/session-update → CloudonixWebhookController@sessionUpdate
POST   /webhooks/cloudonix/cdr           → CloudonixWebhookController@cdr
```
**Middleware**: `VerifyCloudonixSignature`
**Purpose**: Process Cloudonix events and return CXML responses

**Webhook Payload Examples**:

**Call Initiated**:
```json
{
  "event": "call-initiated",
  "call_id": "call-12345",
  "direction": "inbound",
  "from": "+1234567890",
  "to": "+0987654321",
  "timestamp": "2024-01-01T10:00:00Z"
}
```

**Session Update**:
```json
{
  "event": "session-update",
  "call_id": "call-12345",
  "session_id": "session-abc",
  "state": "ringing",
  "timestamp": "2024-01-01T10:00:05Z"
}
```

## Voice Routes (Execution Plane)
**File**: `routes/voice.php`

Real-time call routing with CXML responses.

### Inbound Call Routing
```
POST   /voice/route                    → VoiceRoutingController@handleInbound
```
**Middleware**: `VerifyVoiceWebhookAuth`
**Purpose**: Route external calls via DID configuration
**Response**: CXML routing instructions

### Ring Group Callbacks
```
POST   /callbacks/voice/ring-group-callback → VoiceRoutingController@handleRingGroupCallback
```
**Middleware**: `VerifyVoiceWebhookAuth`
**Purpose**: Handle sequential ring group member dialing

### IVR Input Processing
```
POST   /voice/ivr-input                → VoiceRoutingController@handleIvrInput
```
**Middleware**: `VerifyVoiceWebhookAuth`
**Purpose**: Process DTMF input from IVR menus

## Middleware Details

### Authentication Middleware

#### VerifyVoiceWebhookAuth
**Alias**: `voice.webhook.auth`
**Purpose**: Bearer token authentication for voice routes
**Token Source**: `domain_requests_api_key` from CloudonixSettings
**Response**: CXML error responses

#### VerifyCloudonixSignature
**Alias**: `webhook.signature`
**Purpose**: HMAC-SHA256 signature verification for webhooks
**Secret**: `CLOUDONIX_WEBHOOK_SECRET` environment variable
**Response**: JSON error responses

#### EnsureTenantScope
**Alias**: `tenant.scope`
**Purpose**: Organization-scoped authorization
**Implementation**: Checks user belongs to request organization

### Rate Limiting

#### RateLimitPerOrganization
**Alias**: `rate.limit.organization`
**Purpose**: Organization-level API rate limiting
**Limits**: Configurable per endpoint
**Storage**: Redis-based counters

## Route Model Binding

### Implicit Binding
Routes use implicit model binding with organization scoping:

```php
// Routes automatically resolve Organization model
Route::apiResource('users', UsersController::class);
// Controllers receive Organization-scoped models
public function show(User $user) // $user is already scoped
```

### Custom Binding
Complex routes use explicit binding in `RouteServiceProvider`:

```php
Route::bind('extension', function ($value, $route) {
    return Extension::where('organization_id', $route->parameter('organization'))
                   ->where('id', $value)
                   ->firstOrFail();
});
```

## Route Groups & Organization

### API Route Groups
```php
Route::prefix('api/v1')->middleware(['api'])->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    
    Route::middleware(['auth:sanctum', 'EnsureTenantScope'])->group(function () {
        // All protected API routes
    });
});
```

### Organization Context
All routes include organization context either through:
- URL parameters: `/api/v1/organizations/{organization}/users`
- User context: Authenticated user's organization
- Header injection: `X-Organization-ID`

## Response Formats

### API Responses (Control Plane)
Standard JSON responses with consistent structure:

**Success**:
```json
{
  "data": { /* resource data */ },
  "meta": {
    "pagination": { /* pagination info */ }
  },
  "message": "Operation successful"
}
```

**Error**:
```json
{
  "message": "Validation failed",
  "errors": {
    "field": ["Error message"]
  }
}
```

### Voice Responses (Execution Plane)
CXML responses for Cloudonix:

```xml
<Response>
  <Dial>
    <Number>+1234567890</Number>
  </Dial>
</Response>
```

## Security Considerations

### Authentication Methods
- **API Routes**: Sanctum tokens (session-based)
- **Voice Routes**: Bearer tokens (per-organization)
- **Webhooks**: HMAC signatures (global secret)

### Authorization
- Organization-scoped queries prevent data leakage
- Role-based permissions (Owner/Admin/Agent/User)
- Resource ownership validation

### Rate Limiting
- Organization-level limits prevent abuse
- Burst handling with Redis counters
- Graceful degradation

See `backend/controllers.md` for controller implementations and `backend/services.md` for the business logic these routes trigger.