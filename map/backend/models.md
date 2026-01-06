# Backend Models & Database Schema

## Overview

OpBX uses Eloquent ORM with multi-tenant architecture. All models include organization scoping and follow Laravel conventions.

## Core Entities

### Organization
**Location**: `app/Models/Organization.php`

Root entity for multi-tenancy with global scoping.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `name` | string | Organization display name |
| `domain` | string | Unique domain identifier |
| `settings` | json | Organization settings |

**Relationships**:
- `users()`: HasMany Users
- `extensions()`: HasMany Extensions
- `didNumbers()`: HasMany DidNumbers
- `ringGroups()`: HasMany RingGroups
- `businessHours()`: HasMany BusinessHours
- `ivrMenus()`: HasMany IvrMenus
- `callLogs()`: HasMany CallLogs
- `callDetailRecords()`: HasMany CallDetailRecords
- `conferenceRooms()`: HasMany ConferenceRooms
- `cloudonixSettings()`: HasOne CloudonixSettings

### User
**Location**: `app/Models/User.php`

User accounts with role-based permissions.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `name` | string | Full name |
| `email` | string | Unique email |
| `password` | string | Hashed password |
| `role` | enum | Owner/Admin/Agent/User |
| `is_active` | boolean | Account status |

**Relationships**:
- `organization()`: BelongsTo Organization
- `extensions()`: HasMany Extensions

### Extension
**Location**: `app/Models/Extension.php`

SIP endpoints with Cloudonix synchronization.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `user_id` | bigint | Foreign key to users (nullable) |
| `extension_number` | string | SIP extension (e.g., "1001") |
| `password` | string | SIP password |
| `type` | enum | USER/CONFERENCE/RING_GROUP |
| `is_active` | boolean | Extension status |

**Relationships**:
- `organization()`: BelongsTo Organization
- `user()`: BelongsTo User
- `ringGroupMembers()`: HasMany RingGroupMembers

## Routing Entities

### DidNumber (Phone Numbers)
**Location**: `app/Models/DidNumber.php`

Phone number assignments with routing configuration.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `number` | string | Phone number (E.164 format) |
| `routing_type` | enum | extension/ring_group/business_hours |
| `routing_target_id` | bigint | Target resource ID |

**Relationships**:
- `organization()`: BelongsTo Organization
- `routingExtension()`: BelongsTo Extension (polymorphic)
- `routingRingGroup()`: BelongsTo RingGroup (polymorphic)
- `routingBusinessHours()`: BelongsTo BusinessHours (polymorphic)

### RingGroup
**Location**: `app/Models/RingGroup.php`

Call distribution groups.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `name` | string | Group display name |
| `strategy` | enum | simultaneous/round_robin |
| `ring_timeout` | integer | Seconds before timeout |

**Relationships**:
- `organization()`: BelongsTo Organization
- `members()`: HasMany RingGroupMembers

### RingGroupMember
**Location**: `app/Models/RingGroupMember.php`

Junction table for ring group membership.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `ring_group_id` | bigint | Foreign key to ring_groups |
| `extension_id` | bigint | Foreign key to extensions |
| `priority` | integer | Ring order (lower = higher priority) |

**Relationships**:
- `ringGroup()`: BelongsTo RingGroup
- `extension()`: BelongsTo Extension

### BusinessHours
**Location**: `app/Models/BusinessHours.php`

Time-based routing rules.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `name` | string | Rule display name |
| `timezone` | string | Timezone (e.g., "America/New_York") |
| `is_active` | boolean | Rule status |

**Relationships**:
- `organization()`: BelongsTo Organization
- `schedules()`: HasMany BusinessHoursSchedules

### BusinessHoursSchedule
**Location**: `app/Models/BusinessHoursSchedule.php`

Day-specific time ranges for business hours.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `business_hours_id` | bigint | Foreign key to business_hours |
| `day_of_week` | integer | 0-6 (Sunday=0) |
| `start_time` | time | Start time (HH:MM) |
| `end_time` | time | End time (HH:MM) |

**Relationships**:
- `businessHours()`: BelongsTo BusinessHours

### IvrMenu
**Location**: `app/Models/IvrMenu.php`

Interactive voice response configurations.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `name` | string | Menu display name |
| `greeting_message` | text | TTS greeting |
| `timeout_seconds` | integer | Input timeout |
| `max_attempts` | integer | Retry limit |

**Relationships**:
- `organization()`: BelongsTo Organization
- `options()`: HasMany IvrMenuOptions

### IvrMenuOption
**Location**: `app/Models/IvrMenuOption.php`

Individual IVR menu choices.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `ivr_menu_id` | bigint | Foreign key to ivr_menus |
| `digit` | string | DTMF digit (1-9,0,#,*, etc.) |
| `action_type` | enum | extension/ring_group/business_hours |
| `action_target_id` | bigint | Target resource ID |
| `description` | string | Option description |

**Relationships**:
- `ivrMenu()`: BelongsTo IvrMenu
- `targetExtension()`: BelongsTo Extension (polymorphic)
- `targetRingGroup()`: BelongsTo RingGroup (polymorphic)
- `targetBusinessHours()`: BelongsTo BusinessHours (polymorphic)

## Call Data Entities

### CallLog (Legacy)
**Location**: `app/Models/CallLog.php`

Basic call records for backward compatibility.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `call_id` | string | Unique call identifier |
| `direction` | enum | inbound/outbound |
| `from_number` | string | Caller ID |
| `to_number` | string | Dialed number |
| `start_time` | datetime | Call start |
| `end_time` | datetime | Call end |
| `duration` | integer | Call duration (seconds) |
| `status` | enum | completed/failed/busy |

### CallDetailRecord (CDR)
**Location**: `app/Models/CallDetailRecord.php`

Detailed Cloudonix CDR data.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `call_id` | string | Cloudonix call ID |
| `session_id` | string | Call session identifier |
| `direction` | enum | inbound/outbound |
| `from_number` | string | Caller number |
| `to_number` | string | Destination number |
| `start_time` | datetime | Call initiation |
| `answer_time` | datetime | Call answered |
| `end_time` | datetime | Call termination |
| `duration` | integer | Total duration |
| `billable_duration` | integer | Billable time |
| `status` | string | Final call status |
| `hangup_cause` | string | Termination reason |

### SessionUpdate
**Location**: `app/Models/SessionUpdate.php`

Real-time call state tracking.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `call_id` | string | Call identifier |
| `session_id` | string | Session identifier |
| `event_type` | string | State change type |
| `from_state` | string | Previous state |
| `to_state` | string | New state |
| `timestamp` | datetime | Event timestamp |
| `metadata` | json | Additional event data |

## Configuration Entities

### CloudonixSettings
**Location**: `app/Models/CloudonixSettings.php`

Cloudonix API configuration per organization.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `domain_uuid` | string | Cloudonix domain identifier |
| `api_key` | string | Encrypted API key |
| `webhook_secret` | string | Encrypted webhook secret |

### ConferenceRoom
**Location**: `app/Models/ConferenceRoom.php`

Meeting room configurations.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `organization_id` | bigint | Foreign key to organizations |
| `name` | string | Room display name |
| `extension_number` | string | Conference extension |
| `pin` | string | Access PIN |
| `max_participants` | integer | Participant limit |

## Database Schema Highlights

### Multi-Tenancy Implementation
- All tables include `organization_id` foreign key
- Global scopes enforce tenant isolation
- Cascading deletes maintain data integrity

### Indexing Strategy
- Composite indexes on `(organization_id, created_at)` for time-based queries
- Unique constraints on `(organization_id, extension_number)` for extensions
- Foreign key indexes for performance

### Polymorphic Relationships
- DID routing uses polymorphic relationships for flexible targeting
- IVR menu options support multiple destination types

### JSON Fields
- Organization settings stored as JSON
- Session metadata for extensibility

### Soft Deletes
- User model uses soft deletes to preserve call history
- Extension soft deletes for recovery

## Observers & Events

### Model Observers
- **ExtensionCacheObserver**: Invalidates Redis cache on extension changes
- **BusinessHoursRelatedModelsCacheObserver**: Cache invalidation for business hours
- **BusinessHoursScheduleCacheObserver**: Schedule change handling

### Events
- **CallStateChanged**: Broadcasts call state updates
- **UserCreated**: Triggers extension assignment
- **ExtensionUpdated**: Cloudonix synchronization

See `backend/services.md` for how these models are used in business logic, and `backend/controllers.md` for API endpoints that interact with these models.