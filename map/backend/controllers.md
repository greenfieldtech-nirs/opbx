# Backend Controllers

## Overview

OpBX follows a clear separation between **Control Plane** (configuration CRUD APIs) and **Execution Plane** (real-time call processing). Controllers are organized accordingly.

## Control Plane Controllers (API)

### UsersController
**Location**: `app/Http/Controllers/Api/UsersController.php`

Manages user accounts with role-based access control and tenant scoping.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/users` | List users with pagination, search, filters |
| `store` | `POST /api/v1/users` | Create new user with extension assignment |
| `show` | `GET /api/v1/users/{id}` | Get user details with extensions |
| `update` | `PUT /api/v1/users/{id}` | Update user profile and permissions |
| `destroy` | `DELETE /api/v1/users/{id}` | Soft delete user (preserves call logs) |
| `restore` | `POST /api/v1/users/{id}/restore` | Restore soft-deleted user |

**Key Features**:
- Organization-scoped queries
- Role validation (Owner/Admin/Agent/User)
- Extension auto-assignment
- Password hashing and secure updates

### ExtensionsController
**Location**: `app/Http/Controllers/Api/ExtensionsController.php`

Manages SIP extensions with Cloudonix synchronization.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/extensions` | List extensions with user associations |
| `store` | `POST /api/v1/extensions` | Create extension with SIP password |
| `show` | `GET /api/v1/extensions/{id}` | Get extension details |
| `update` | `PUT /api/v1/extensions/{id}` | Update extension settings |
| `destroy` | `DELETE /api/v1/extensions/{id}` | Delete extension |
| `regeneratePassword` | `POST /api/v1/extensions/{id}/regenerate-password` | Generate new SIP password |

**Key Features**:
- Automatic SIP password generation
- Cloudonix API synchronization
- Extension number validation
- Type management (USER, CONFERENCE, RING_GROUP)

### RingGroupsController
**Location**: `app/Http/Controllers/Api/RingGroupsController.php`

Manages call distribution groups with various strategies.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/ring-groups` | List ring groups with member counts |
| `store` | `POST /api/v1/ring-groups` | Create ring group with strategy |
| `show` | `GET /api/v1/ring-groups/{id}` | Get ring group with members |
| `update` | `PUT /api/v1/ring-groups/{id}` | Update group settings and members |
| `destroy` | `DELETE /api/v1/ring-groups/{id}` | Delete ring group |
| `addMember` | `POST /api/v1/ring-groups/{id}/members` | Add extension to ring group |
| `removeMember` | `DELETE /api/v1/ring-groups/{id}/members/{memberId}` | Remove member |

**Key Features**:
- Strategy management (simultaneous, round-robin)
- Member priority ordering
- Extension validation (can't be in multiple groups)

### BusinessHoursController
**Location**: `app/Http/Controllers/Api/BusinessHoursController.php`

Manages time-based routing configurations.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/business-hours` | List business hours rules |
| `store` | `POST /api/v1/business-hours` | Create time-based routing rule |
| `show` | `GET /api/v1/business-hours/{id}` | Get rule with schedules |
| `update` | `PUT /api/v1/business-hours/{id}` | Update rule and schedules |
| `destroy` | `DELETE /api/v1/business-hours/{id}` | Delete rule |

**Key Features**:
- Day-of-week scheduling
- Time range management
- Routing destination configuration

### PhoneNumbersController (DIDs)
**Location**: `app/Http/Controllers/Api/PhoneNumbersController.php`

Manages phone number assignments and routing.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/phone-numbers` | List organization DIDs |
| `store` | `POST /api/v1/phone-numbers` | Assign DID with routing |
| `show` | `GET /api/v1/phone-numbers/{id}` | Get DID details |
| `update` | `PUT /api/v1/phone-numbers/{id}` | Update routing configuration |
| `destroy` | `DELETE /api/v1/phone-numbers/{id}` | Remove DID assignment |

**Key Features**:
- Routing type validation (extension, ring_group, business_hours)
- Cloudonix synchronization

### CallLogController & CallDetailRecordController
**Location**: `app/Http/Controllers/Api/CallLogController.php`

Provides read-only access to call history.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/call-logs` | List basic call records |
| `show` | `GET /api/v1/call-logs/{id}` | Get call details |
| `index` | `GET /api/v1/call-detail-records` | List detailed CDR data |

**Key Features**:
- Date range filtering
- Extension/DID filtering
- Performance optimized queries

### ConferenceRoomController
**Location**: `app/Http/Controllers/Api/ConferenceRoomController.php`

Manages meeting room configurations.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/conference-rooms` | List conference rooms |
| `store` | `POST /api/v1/conference-rooms` | Create conference room |
| `update` | `PUT /api/v1/conference-rooms/{id}` | Update room settings |
| `destroy` | `DELETE /api/v1/conference-rooms/{id}` | Delete room |

### IvrMenuController
**Location**: `app/Http/Controllers/Api/IvrMenuController.php`

Manages interactive voice response menus.

| Method | Route | Functionality |
|--------|-------|---------------|
| `index` | `GET /api/v1/ivr-menus` | List IVR menus |
| `store` | `POST /api/v1/ivr-menus` | Create IVR menu |
| `update` | `PUT /api/v1/ivr-menus/{id}` | Update menu options |
| `destroy` | `DELETE /api/v1/ivr-menus/{id}` | Delete menu |

### AuthController & ProfileController
**Location**: `app/Http/Controllers/Api/AuthController.php`

Authentication and profile management.

| Controller | Method | Route | Functionality |
|------------|--------|-------|---------------|
| AuthController | `login` | `POST /api/v1/auth/login` | Sanctum token authentication |
| ProfileController | `show` | `GET /api/v1/profile` | Get current user profile |
| ProfileController | `update` | `PUT /api/v1/profile` | Update user profile |

### SettingsController
**Location**: `app/Http/Controllers/Api/SettingsController.php`

Organization-level configuration (Owner only).

| Method | Route | Functionality |
|--------|-------|---------------|
| `show` | `GET /api/v1/settings` | Get organization settings |
| `update` | `PUT /api/v1/settings` | Update Cloudonix integration |

## Execution Plane Controllers (Real-time)

### CloudonixWebhookController
**Location**: `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`

Processes Cloudonix webhook events with idempotency and state management.

| Method | Route | Functionality |
|--------|-------|---------------|
| `callInitiated` | `POST /webhooks/cloudonix/call-initiated` | Process inbound call, return CXML routing |
| `sessionUpdate` | `POST /webhooks/cloudonix/session-update` | Handle call state changes |
| `cdr` | `POST /webhooks/cloudonix/cdr` | Process call completion data |

**Key Features**:
- Idempotency key validation
- Redis-based distributed locking
- Webhook signature verification
- CXML response generation

### VoiceRoutingController
**Location**: `app/Http/Controllers/Voice/VoiceRoutingController.php`

Real-time call routing decisions.

| Method | Route | Functionality |
|--------|-------|---------------|
| `handleInbound` | `POST /voice/route` | Route external calls via DID |
| `handleRingGroupCallback` | `POST /callbacks/voice/ring-group-callback` | Sequential ring group routing |
| `handleIvrInput` | `POST /voice/ivr-input` | Process IVR digit input |

**Key Features**:
- Minimal database queries (Redis caching)
- CXML generation for Cloudonix
- State machine management
- Race condition prevention

## Security & Middleware

### Authentication Middleware
- **VerifyVoiceWebhookAuth**: Bearer token validation for voice routes
- **VerifyCloudonixSignature**: HMAC-SHA256 signature verification for webhooks
- **EnsureTenantScope**: Organization-scoped authorization

### Rate Limiting
- **RateLimitPerOrganization**: Organization-level API rate limiting

## Common Patterns

### Response Format
All controllers return consistent JSON responses:
```json
{
  "data": { /* resource data */ },
  "meta": { /* pagination, counts */ },
  "message": "Success message"
}
```

### Error Handling
- Validation errors: 422 with field-specific messages
- Authorization errors: 403 Forbidden
- Not found: 404 with resource type
- Server errors: 500 with correlation ID

### Validation
- Form request classes for complex validation
- Organization scope validation
- Permission checking in policies

See `backend/models.md` for model relationships and `backend/services.md` for business logic implementation details.