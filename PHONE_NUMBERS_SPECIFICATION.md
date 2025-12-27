# Phone Numbers (DIDs) Management - Detailed Specification

## 1. Overview

### Purpose
The Phone Numbers module manages inbound telephone numbers (DIDs - Direct Inward Dialing) and their routing configuration. Phone numbers are the entry points for calls into the PBX system, and this module determines where each incoming call should be routed.

### Key Concepts
- A phone number (DID) represents an inbound telephone number purchased from Cloudonix
- Each phone number routes to exactly ONE resource type
- Routing resources can be: Extensions, Business Hours schedules, Conference Rooms, or Ring Groups
- Phone numbers have active/inactive status for temporary disabling
- All routing decisions happen at runtime via Cloudonix webhooks
- Phone numbers are organization-scoped (multi-tenant)

### Architecture Pattern
- **Control Plane**: CRUD operations for phone number configuration (Laravel API + MySQL)
- **Execution Plane**: Runtime call routing via webhook handlers (Redis-based state + CXML responses)

---

## 2. Data Model

### Phone Number Fields

#### Basic Information
- **ID**: Unique identifier (auto-generated)
- **Organization ID**: Tenant isolation (required)
- **Phone Number**: E.164 format telephone number
  - Format: `+[country code][area code][number]`
  - Example: `+12125551234`, `+442071234567`, `+97235551234`
  - Max length: 20 characters
  - Validation: Required, unique across ALL organizations, must start with '+'
  - Regex: `^\+[1-9]\d{1,14}$`

- **Friendly Name**: Human-readable label (optional)
  - Example: "Main Office Line", "Support Hotline", "Sales Direct"
  - Max length: 255 characters
  - Helps identify the number's purpose

- **Status**: Active/Inactive enum (required)
  - **Active**: Phone number receives and routes calls
  - **Inactive**: Phone number rejects calls (useful for temporary disabling)
  - Default: 'active'

#### Routing Configuration
- **Routing Type**: Where calls to this number should go (required)
  - **extension**: Route directly to a specific extension
  - **ring_group**: Route to a ring group (multiple extensions)
  - **business_hours**: Route based on business hours schedule (time-based routing)
  - **conference_room**: Route directly to a conference room

- **Routing Config**: JSON field containing routing target details (required)
  - Structure varies by routing_type:

  ```json
  // For routing_type: "extension"
  {
    "extension_id": 123  // ID of target extension
  }

  // For routing_type: "ring_group"
  {
    "ring_group_id": 45  // ID of target ring group
  }

  // For routing_type: "business_hours"
  {
    "business_hours_schedule_id": 6  // ID of business hours schedule
  }

  // For routing_type: "conference_room"
  {
    "conference_room_id": 78  // ID of target conference room
  }
  ```

- **Validation Rules**:
  - The target resource ID MUST exist and belong to the same organization
  - The target resource MUST be active/enabled
  - For extensions: Can be any extension type (user, virtual, queue)
  - For ring groups: Ring group must have at least 1 active member
  - For business hours: Schedule must have at least one configured action
  - For conference rooms: Room must be active

#### Cloudonix Integration
- **Cloudonix Config**: JSON field for Cloudonix-specific settings (optional)
  - Stores Cloudonix phone number metadata
  - Example structure:
  ```json
  {
    "number_id": "did_abc123",          // Cloudonix's internal DID ID
    "purchased_at": "2024-01-15T10:30:00Z",
    "monthly_cost": 1.50,
    "capabilities": ["voice", "sms"],
    "region": "US-NY",
    "carrier": "bandwidth"
  }
  ```
  - This field is populated by integration with Cloudonix REST API
  - Not editable by users directly

#### Timestamps
- **Created At**: ISO 8601 timestamp (auto-generated)
- **Updated At**: ISO 8601 timestamp (auto-updated)

---

## 3. Database Schema

### Table: `did_numbers`

**Columns:**
- `id` - BIGINT UNSIGNED, Primary Key, Auto Increment
- `organization_id` - BIGINT UNSIGNED, Foreign Key → organizations.id, CASCADE ON DELETE
- `phone_number` - VARCHAR(20), UNIQUE, NOT NULL
- `friendly_name` - VARCHAR(255), NULLABLE
- `routing_type` - ENUM('extension', 'ring_group', 'business_hours', 'conference_room'), NOT NULL
- `routing_config` - JSON, NOT NULL
- `status` - ENUM('active', 'inactive'), NOT NULL, DEFAULT 'active'
- `cloudonix_config` - JSON, NULLABLE
- `created_at` - TIMESTAMP, NOT NULL
- `updated_at` - TIMESTAMP, NOT NULL

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `did_numbers_phone_number_unique` (`phone_number`)
- INDEX `did_numbers_organization_id_status_index` (`organization_id`, `status`)
- INDEX `did_numbers_phone_number_index` (`phone_number`)
- FOREIGN KEY `did_numbers_organization_id_foreign` (`organization_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE

**Important Notes:**
- The `phone_number` field has a UNIQUE constraint across ALL organizations
- This prevents duplicate phone numbers in the system (as DIDs are globally unique)
- Organization scoping is handled via the `organization_id` foreign key
- The `routing_config` JSON structure is validated at the application level

---

## 4. User Roles & Permissions

### Owner
- ✅ View all phone numbers in organization
- ✅ Create/purchase phone numbers
- ✅ Edit phone number routing
- ✅ Change phone number status (active/inactive)
- ✅ Delete/release phone numbers
- ✅ View Cloudonix configuration

### PBX Admin
- ✅ View all phone numbers in organization
- ✅ Create/purchase phone numbers
- ✅ Edit phone number routing
- ✅ Change phone number status (active/inactive)
- ✅ Delete/release phone numbers
- ✅ View Cloudonix configuration

### PBX User
- ✅ View phone numbers (read-only)
- ❌ Cannot create/edit/delete

### Reporter
- ✅ View phone numbers (read-only)
- ❌ Cannot create/edit/delete

**Authorization Policy:**
```php
// app/Policies/DidNumberPolicy.php

public function viewAny(User $user): bool
{
    // All authenticated users can view phone numbers in their org
    return true;
}

public function view(User $user, DidNumber $didNumber): bool
{
    // User must be in same organization
    return $user->organization_id === $didNumber->organization_id;
}

public function create(User $user): bool
{
    // Only Owner and PBX Admin can create phone numbers
    return $user->isOwner() || $user->isPBXAdmin();
}

public function update(User $user, DidNumber $didNumber): bool
{
    // Only Owner and PBX Admin can update phone numbers
    return ($user->isOwner() || $user->isPBXAdmin())
        && $user->organization_id === $didNumber->organization_id;
}

public function delete(User $user, DidNumber $didNumber): bool
{
    // Only Owner and PBX Admin can delete phone numbers
    return ($user->isOwner() || $user->isPBXAdmin())
        && $user->organization_id === $didNumber->organization_id;
}
```

---

## 5. Page Layout & UI Components

### Main Phone Numbers Page (`/phone-numbers`)

#### Header Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Phone Numbers                              [+ Add Phone Number] │
│ Manage inbound phone numbers and routing                        │
└─────────────────────────────────────────────────────────────────┘
```

#### Filters & Search Bar
```
┌─────────────────────────────────────────────────────────────────┐
│ [🔍 Search phone numbers...]  [Routing Type ▼]  [Status ▼]     │
└─────────────────────────────────────────────────────────────────┘
```

**Filters:**
- **Search**: Searches phone_number and friendly_name (debounced 300ms)
- **Routing Type Dropdown**: All / Extension / Ring Group / Business Hours / Conference Room
- **Status Dropdown**: All / Active / Inactive

#### Phone Numbers Table

**Columns:**

1. **Phone Number** (sortable)
   - Primary display with icon
   - Format: `+1 (212) 555-1234` (formatted for readability)
   - Friendly name shown below in smaller, muted text
   - Icon: Phone icon (blue)

2. **Routing Type** (sortable)
   - Badge with icon:
     - Extension: "Extension" (blue badge, user icon)
     - Ring Group: "Ring Group" (purple badge, users icon)
     - Business Hours: "Business Hours" (green badge, clock icon)
     - Conference Room: "Conference Room" (orange badge, video icon)

3. **Destination** (not sortable)
   - Shows the target resource name:
     - Extension: "Ext 101 - John Doe"
     - Ring Group: "Sales Team"
     - Business Hours: "Main Schedule"
     - Conference Room: "Board Room"
   - If target is deleted/missing: "⚠️ Invalid destination" (red text)

4. **Status** (sortable)
   - Badge:
     - Active: Green badge "Active"
     - Inactive: Gray badge "Inactive"

5. **Actions**
   - **Edit** button (ghost variant)
   - **Delete** button (ghost variant, destructive)
   - Click row to open edit dialog

**Empty State:**
```
┌─────────────────────────────────────────────────────────────────┐
│                                                                  │
│                         📞                                       │
│                                                                  │
│               No phone numbers found                             │
│                                                                  │
│   Get started by adding your first phone number                 │
│                                                                  │
│                  [+ Add Phone Number]                            │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

#### Pagination
- Shows: "Showing 1-20 of 45 phone numbers"
- Standard pagination controls (previous/next, page numbers)
- Configurable page size: 10, 20, 50, 100

---

## 6. Create/Edit Phone Number Dialog

### Dialog Structure

**Dialog Title:**
- Create: "Add Phone Number"
- Edit: "Edit Phone Number - +1 (212) 555-1234"

**Dialog Size:** Medium (600px width)

### Form Fields

#### Section 1: Basic Information

**Phone Number** (Text Input) - _Required, only shown on create_
```
┌─────────────────────────────────────────────┐
│ Phone Number *                               │
│ ┌─────────────────────────────────────────┐ │
│ │ +12125551234                            │ │
│ └─────────────────────────────────────────┘ │
│ Enter in E.164 format: +[country][number]   │
└─────────────────────────────────────────────┘
```
- Validation: Required, must match `^\+[1-9]\d{1,14}$`, unique
- Real-time validation on blur
- Error message: "Phone number must be in E.164 format (+12125551234)"
- On edit: Field is disabled (read-only), cannot change phone number

**Friendly Name** (Text Input) - _Optional_
```
┌─────────────────────────────────────────────┐
│ Friendly Name                                │
│ ┌─────────────────────────────────────────┐ │
│ │ Main Office Line                        │ │
│ └─────────────────────────────────────────┘ │
│ Optional: Give this number a memorable name  │
└─────────────────────────────────────────────┘
```
- Max length: 255 characters
- Placeholder: "e.g., Main Office, Support Hotline"

**Status** (Radio Group) - _Required_
```
┌─────────────────────────────────────────────┐
│ Status *                                     │
│  ○ Active    ● Inactive                      │
│ Inactive numbers will reject incoming calls  │
└─────────────────────────────────────────────┘
```
- Default: Active

#### Section 2: Routing Configuration

**Routing Type** (Select Dropdown) - _Required_
```
┌─────────────────────────────────────────────┐
│ Route calls to *                             │
│ ┌─────────────────────────────────────────┐ │
│ │ Extension                          ▼    │ │
│ └─────────────────────────────────────────┘ │
│ Choose where calls should be routed          │
└─────────────────────────────────────────────┘
```

**Options:**
- Extension
- Ring Group
- Business Hours
- Conference Room

**Conditional Fields (based on Routing Type):**

**If routing_type === "extension":**
```
┌─────────────────────────────────────────────┐
│ Target Extension *                           │
│ ┌─────────────────────────────────────────┐ │
│ │ 101 - John Doe                     ▼    │ │
│ └─────────────────────────────────────────┘ │
│ Calls will ring this extension directly     │
└─────────────────────────────────────────────┘
```
- Dropdown populated with active extensions from organization
- Display format: `{extension_number} - {name}`
- Sorted by extension number
- Shows status indicator (only active extensions selectable)

**If routing_type === "ring_group":**
```
┌─────────────────────────────────────────────┐
│ Target Ring Group *                          │
│ ┌─────────────────────────────────────────┐ │
│ │ Sales Team                         ▼    │ │
│ └─────────────────────────────────────────┘ │
│ Calls will use this ring group's strategy   │
└─────────────────────────────────────────────┘
```
- Dropdown populated with active ring groups from organization
- Display format: `{name} ({member_count} members)`
- Sorted alphabetically
- Shows strategy badge next to name

**If routing_type === "business_hours":**
```
┌─────────────────────────────────────────────┐
│ Business Hours Schedule *                    │
│ ┌─────────────────────────────────────────┐ │
│ │ Main Schedule                      ▼    │ │
│ └─────────────────────────────────────────┘ │
│ Calls will route based on time of day       │
└─────────────────────────────────────────────┘
```
- Dropdown populated with active business hours schedules from organization
- Display format: `{name}`
- Sorted alphabetically
- Info text explains open/closed hours routing

**If routing_type === "conference_room":**
```
┌─────────────────────────────────────────────┐
│ Target Conference Room *                     │
│ ┌─────────────────────────────────────────┐ │
│ │ Board Room                         ▼    │ │
│ └─────────────────────────────────────────┘ │
│ Calls will join this conference room        │
└─────────────────────────────────────────────┘
```
- Dropdown populated with active conference rooms from organization
- Display format: `{name} ({max_participants} max)`
- Sorted alphabetically
- Shows capacity indicator

### Dialog Actions

**Buttons:**
- **Cancel**: Close dialog, discard changes
- **Save** / **Update**: Submit form, validate, and save
  - Disabled until form is valid
  - Shows loading spinner during save
  - On success: Close dialog, show success toast, refresh list
  - On error: Show error message inline or in toast

---

## 7. API Endpoints

### Base URL
`/api/v1/phone-numbers`

### Endpoints

#### 1. List All Phone Numbers
```http
GET /api/v1/phone-numbers
```

**Query Parameters:**
- `page` (integer, optional): Page number, default: 1
- `per_page` (integer, optional): Items per page, default: 20, max: 100
- `status` (string, optional): Filter by status ('active', 'inactive')
- `routing_type` (string, optional): Filter by routing type
- `search` (string, optional): Search phone_number or friendly_name

**Response:** 200 OK
```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 5,
      "phone_number": "+12125551234",
      "friendly_name": "Main Office Line",
      "routing_type": "extension",
      "routing_config": {
        "extension_id": 123
      },
      "status": "active",
      "cloudonix_config": {
        "number_id": "did_abc123",
        "purchased_at": "2024-01-15T10:30:00Z"
      },
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z",
      "extension": {
        "id": 123,
        "extension_number": "101",
        "name": "John Doe",
        "status": "active"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 45,
    "last_page": 3
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Not authenticated
- `403 Forbidden`: Not authorized to view phone numbers

---

#### 2. Get Single Phone Number
```http
GET /api/v1/phone-numbers/{id}
```

**Response:** 200 OK
```json
{
  "data": {
    "id": 1,
    "organization_id": 5,
    "phone_number": "+12125551234",
    "friendly_name": "Main Office Line",
    "routing_type": "extension",
    "routing_config": {
      "extension_id": 123
    },
    "status": "active",
    "cloudonix_config": {
      "number_id": "did_abc123"
    },
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-15T10:30:00Z",
    "extension": {
      "id": 123,
      "extension_number": "101",
      "name": "John Doe",
      "status": "active"
    }
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Not authenticated
- `403 Forbidden`: Phone number belongs to different organization
- `404 Not Found`: Phone number does not exist

---

#### 3. Create Phone Number
```http
POST /api/v1/phone-numbers
```

**Request Body:**
```json
{
  "phone_number": "+12125551234",
  "friendly_name": "Main Office Line",
  "routing_type": "extension",
  "routing_config": {
    "extension_id": 123
  },
  "status": "active"
}
```

**Validation Rules:**
- `phone_number`: required, string, max:20, regex:`^\+[1-9]\d{1,14}$`, unique
- `friendly_name`: nullable, string, max:255
- `routing_type`: required, in:['extension','ring_group','business_hours','conference_room']
- `routing_config`: required, json, must contain valid target ID for routing_type
- `status`: required, in:['active','inactive']

**Response:** 201 Created
```json
{
  "data": {
    "id": 1,
    "phone_number": "+12125551234",
    "friendly_name": "Main Office Line",
    "routing_type": "extension",
    "routing_config": {
      "extension_id": 123
    },
    "status": "active",
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Not authenticated
- `403 Forbidden`: User role not authorized (must be Owner or PBX Admin)
- `422 Unprocessable Entity`: Validation errors
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "phone_number": [
      "The phone number has already been taken."
    ],
    "routing_config.extension_id": [
      "The selected extension does not exist or is not active."
    ]
  }
}
```

---

#### 4. Update Phone Number
```http
PUT /api/v1/phone-numbers/{id}
```

**Request Body:**
```json
{
  "friendly_name": "Main Office Line",
  "routing_type": "ring_group",
  "routing_config": {
    "ring_group_id": 45
  },
  "status": "active"
}
```

**Validation Rules:**
- `friendly_name`: nullable, string, max:255
- `routing_type`: required, in:['extension','ring_group','business_hours','conference_room']
- `routing_config`: required, json, must contain valid target ID for routing_type
- `status`: required, in:['active','inactive']
- `phone_number`: CANNOT be updated (immutable after creation)

**Response:** 200 OK
```json
{
  "data": {
    "id": 1,
    "phone_number": "+12125551234",
    "friendly_name": "Main Office Line",
    "routing_type": "ring_group",
    "routing_config": {
      "ring_group_id": 45
    },
    "status": "active",
    "updated_at": "2024-01-15T11:30:00Z"
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Not authenticated
- `403 Forbidden`: User not authorized or phone number belongs to different org
- `404 Not Found`: Phone number does not exist
- `422 Unprocessable Entity`: Validation errors

---

#### 5. Delete Phone Number
```http
DELETE /api/v1/phone-numbers/{id}
```

**Response:** 204 No Content

**Error Responses:**
- `401 Unauthorized`: Not authenticated
- `403 Forbidden`: User not authorized or phone number belongs to different org
- `404 Not Found`: Phone number does not exist

---

## 8. Business Logic & Validation

### Validation Rules Summary

#### Phone Number Format Validation
```php
'phone_number' => [
    'required',
    'string',
    'max:20',
    'regex:/^\+[1-9]\d{1,14}$/',
    'unique:did_numbers,phone_number'
]
```

**Valid Examples:**
- `+12125551234` (US)
- `+442071234567` (UK)
- `+97235551234` (Israel)

**Invalid Examples:**
- `2125551234` (missing + prefix)
- `+0123456789` (starts with 0)
- `+1-212-555-1234` (contains hyphens)

#### Routing Config Validation

**For Extension:**
```php
'routing_config.extension_id' => [
    'required',
    'integer',
    'exists:extensions,id,organization_id,' . $user->organization_id . ',deleted_at,NULL'
]
```

**For Ring Group:**
```php
'routing_config.ring_group_id' => [
    'required',
    'integer',
    'exists:ring_groups,id,organization_id,' . $user->organization_id . ',deleted_at,NULL'
]
```

**For Business Hours:**
```php
'routing_config.business_hours_schedule_id' => [
    'required',
    'integer',
    'exists:business_hours_schedules,id,organization_id,' . $user->organization_id . ',deleted_at,NULL'
]
```

**For Conference Room:**
```php
'routing_config.conference_room_id' => [
    'required',
    'integer',
    'exists:conference_rooms,id,organization_id,' . $user->organization_id
]
```

### Custom Validation Rules

#### 1. Target Resource Must Be Active
```php
// In custom validator
$validator->after(function ($validator) {
    $routingType = $this->input('routing_type');
    $routingConfig = $this->input('routing_config');

    match($routingType) {
        'extension' => $this->validateExtensionIsActive($routingConfig['extension_id'], $validator),
        'ring_group' => $this->validateRingGroupIsActive($routingConfig['ring_group_id'], $validator),
        'business_hours' => $this->validateBusinessHoursIsActive($routingConfig['business_hours_schedule_id'], $validator),
        'conference_room' => $this->validateConferenceRoomIsActive($routingConfig['conference_room_id'], $validator),
    };
});
```

#### 2. Ring Group Must Have Active Members
```php
private function validateRingGroupIsActive(int $ringGroupId, $validator): void
{
    $ringGroup = RingGroup::find($ringGroupId);

    if (!$ringGroup || $ringGroup->status !== 'active') {
        $validator->errors()->add(
            'routing_config.ring_group_id',
            'The selected ring group is not active.'
        );
    }

    $activeMemberCount = $ringGroup->members()
        ->whereHas('extension', fn($q) => $q->where('status', 'active'))
        ->count();

    if ($activeMemberCount === 0) {
        $validator->errors()->add(
            'routing_config.ring_group_id',
            'The selected ring group has no active members.'
        );
    }
}
```

#### 3. Cannot Delete Phone Number If Actively In Use
This is a soft constraint - we allow deletion but log a warning:
```php
public function delete(DidNumber $didNumber): void
{
    // Check if phone number has recent call activity
    $recentCallCount = CallLog::where('did_number_id', $didNumber->id)
        ->where('created_at', '>=', now()->subHours(24))
        ->count();

    if ($recentCallCount > 0) {
        Log::warning('Phone number with recent call activity deleted', [
            'did_number_id' => $didNumber->id,
            'phone_number' => $didNumber->phone_number,
            'recent_calls' => $recentCallCount
        ]);
    }

    $didNumber->delete();
}
```

---

## 9. Runtime Call Routing (Execution Plane)

### Webhook Flow

When a call arrives at Cloudonix for a phone number:

1. **Cloudonix sends webhook** to `/api/webhooks/cloudonix/call-initiated`
2. **Webhook includes**: `to_number` (the DID that was called), `from_number`, `call_id`
3. **Our system**:
   - Looks up `DidNumber` by `phone_number`
   - Checks organization and status
   - Loads routing configuration
   - Determines call routing based on `routing_type`
4. **Response**: CXML instructions for Cloudonix

### Routing Logic by Type

#### Extension Routing
```xml
<!-- Direct extension routing -->
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <Extension>{extension_number}</Extension>
  </Connect>
</Response>
```

#### Ring Group Routing
```xml
<!-- Ring group routing - Cloudonix handles the ring strategy -->
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <RingGroup id="{ring_group_id}" strategy="{strategy}" timeout="{timeout}">
      <Member>{extension_1}</Member>
      <Member>{extension_2}</Member>
      <!-- ... more members -->
    </RingGroup>
  </Connect>
</Response>
```

#### Business Hours Routing
```php
// Evaluate current time against schedule
$schedule = BusinessHoursSchedule::find($config['business_hours_schedule_id']);
$isOpen = $schedule->isOpenNow(now());

if ($isOpen) {
    // Route to open_hours_action (could be extension, ring group, etc.)
    return CxmlBuilder::routeToAction($schedule->open_hours_action);
} else {
    // Route to closed_hours_action
    return CxmlBuilder::routeToAction($schedule->closed_hours_action);
}
```

#### Conference Room Routing
```xml
<!-- Conference room routing -->
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <Conference id="{conference_room_id}"
                pin="{pin}"
                hostPin="{host_pin}"
                maxParticipants="{max_participants}"
                waitForHost="{wait_for_host}"
                muteOnEntry="{mute_on_entry}">
      {conference_room_name}
    </Conference>
  </Connect>
</Response>
```

### Error Handling in Runtime

**If phone number not found:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="alice">The number you have dialed is not configured. Please contact support.</Say>
  <Hangup/>
</Response>
```

**If phone number is inactive:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="alice">This number is temporarily unavailable. Please try again later.</Say>
  <Hangup/>
</Response>
```

**If target resource is invalid/deleted:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="alice">We're sorry, but this call cannot be completed. Please contact support.</Say>
  <Hangup/>
</Response>
```

---

## 10. Integration with Cloudonix

### Cloudonix REST API Integration

#### Purchase New Phone Number
```http
POST https://api.cloudonix.io/v1/phone-numbers/purchase
Authorization: Bearer {api_token}

{
  "country": "US",
  "area_code": "212",
  "capabilities": ["voice"]
}

Response:
{
  "number_id": "did_abc123",
  "phone_number": "+12125551234",
  "monthly_cost": 1.50,
  "capabilities": ["voice", "sms"]
}
```

After purchasing from Cloudonix:
1. Create `DidNumber` record in our database
2. Store Cloudonix `number_id` in `cloudonix_config`
3. Set initial routing configuration
4. Configure webhook URL in Cloudonix portal

#### Release Phone Number
```http
DELETE https://api.cloudonix.io/v1/phone-numbers/{number_id}
Authorization: Bearer {api_token}

Response: 204 No Content
```

Before releasing:
1. Soft-delete `DidNumber` record
2. Log deletion event
3. Release from Cloudonix

### Webhook Configuration

Each phone number in Cloudonix must be configured with:
- **Inbound Call Webhook URL**: `https://your-pbx.com/api/webhooks/cloudonix/call-initiated`
- **Call Status Callback URL**: `https://your-pbx.com/api/webhooks/cloudonix/call-status`

---

## 11. Error Handling

### User-Facing Errors

#### Invalid Phone Number Format
```
Error: Phone number must be in E.164 format.
Example: +12125551234
```

#### Duplicate Phone Number
```
Error: This phone number is already registered in the system.
```

#### Invalid Target Resource
```
Error: The selected extension does not exist or has been deleted.
Please choose a different extension.
```

#### Ring Group With No Members
```
Error: The selected ring group has no active members.
Please add members to the ring group before assigning it to a phone number.
```

### System-Level Errors

#### Database Constraint Violations
- Log error with full context
- Return generic user-friendly message
- Alert system administrators

#### Cloudonix API Failures
- Retry with exponential backoff (3 attempts)
- Log failure details
- Show user: "Unable to communicate with phone service. Please try again."

---

## 12. Testing Requirements

### Unit Tests

#### Model Tests
```php
// tests/Unit/Models/DidNumberTest.php

test('phone number must be in E.164 format');
test('phone number must be unique across all organizations');
test('routing config validates based on routing type');
test('can get target extension ID from routing config');
test('can get target ring group ID from routing config');
test('can get target business hours ID from routing config');
test('can get target conference room ID from routing config');
test('isActive() returns true when status is active');
```

#### Validation Tests
```php
// tests/Unit/Requests/StoreDidNumberRequestTest.php

test('phone number is required');
test('phone number must start with plus sign');
test('phone number cannot contain spaces or hyphens');
test('phone number must be unique');
test('routing type must be valid enum value');
test('routing config must contain required keys for routing type');
test('extension_id must exist and be active');
test('ring_group_id must exist and have active members');
test('business_hours_schedule_id must exist and be active');
test('conference_room_id must exist and be active');
```

### Integration Tests

#### API Tests
```php
// tests/Feature/Api/PhoneNumberControllerTest.php

test('owner can create phone number');
test('pbx admin can create phone number');
test('pbx user cannot create phone number');
test('phone number routes to correct organization');
test('can list all phone numbers for organization');
test('can filter phone numbers by status');
test('can filter phone numbers by routing type');
test('can search phone numbers by phone number or friendly name');
test('can update phone number routing configuration');
test('cannot update phone number itself (immutable)');
test('can delete phone number');
test('cannot access phone numbers from different organization');
```

#### Routing Tests
```php
// tests/Feature/Routing/PhoneNumberRoutingTest.php

test('inactive phone number rejects calls');
test('extension routing generates correct CXML');
test('ring group routing generates correct CXML');
test('business hours routing evaluates time correctly');
test('conference room routing generates correct CXML');
test('invalid phone number returns error CXML');
test('deleted target resource returns error CXML');
```

### UI Tests (E2E)

```typescript
// frontend/tests/e2e/phone-numbers.spec.ts

test('can view phone numbers list');
test('can filter by status');
test('can filter by routing type');
test('can search by phone number');
test('can create phone number with extension routing');
test('can create phone number with ring group routing');
test('can create phone number with business hours routing');
test('can create phone number with conference room routing');
test('validates phone number format');
test('shows error if target resource is invalid');
test('can edit phone number routing');
test('can delete phone number');
test('phone number field is disabled when editing');
```

---

## 13. Implementation Checklist

### Backend

- [ ] Update migration to remove 'ivr' and 'voicemail' from routing_type enum
- [ ] Create `DidNumberPolicy` with authorization rules
- [ ] Create `DidNumberController` with full CRUD operations
- [ ] Create `StoreDidNumberRequest` with validation rules
- [ ] Create `UpdateDidNumberRequest` with validation rules
- [ ] Create `DidNumberResource` for API responses
- [ ] Add eager loading for related resources (extension, ring_group, etc.)
- [ ] Implement custom validation for target resource existence and status
- [ ] Add API routes to `routes/api.php`
- [ ] Write unit tests for model and validation
- [ ] Write feature tests for API endpoints
- [ ] Write tests for routing logic

### Frontend

- [ ] Update `DIDNumber` TypeScript types to match new routing_type values
- [ ] Update `didsService.ts` to use correct endpoints
- [ ] Implement full Phone Numbers page with table and filters
- [ ] Create Create/Edit Phone Number dialog component
- [ ] Implement conditional routing config fields based on routing type
- [ ] Add phone number formatting utility
- [ ] Integrate React Query for data fetching
- [ ] Add loading and error states
- [ ] Add empty state
- [ ] Add success/error toasts
- [ ] Write E2E tests for UI flows

### Integration

- [ ] Test phone number creation end-to-end
- [ ] Test routing for all 4 routing types
- [ ] Verify webhook handling for each routing type
- [ ] Test error scenarios (inactive number, deleted target, etc.)
- [ ] Validate Cloudonix integration (if applicable)

### Documentation

- [ ] Update API documentation
- [ ] Update CHANGELOG.md
- [ ] Add inline code documentation
- [ ] Update README with phone numbers feature

---

## 14. Migration Plan

### Update Existing Migration

**File**: `database/migrations/2024_01_01_000004_create_did_numbers_table.php`

**Changes Needed:**
```php
// Change routing_type enum from:
$table->enum('routing_type', [
    'extension',
    'ring_group',
    'business_hours',
    'ivr',           // REMOVE
    'voicemail'      // REMOVE
])->default('extension');

// To:
$table->enum('routing_type', [
    'extension',
    'ring_group',
    'business_hours',
    'conference_room'  // ADD
])->default('extension');
```

**If database already has data:**
1. Create new migration to modify enum
2. Convert any existing 'ivr' or 'voicemail' records to 'extension' (or another appropriate type)
3. Run migration

---

## 15. Future Enhancements (Out of Scope for v1)

- [ ] Bulk import phone numbers from CSV
- [ ] Call forwarding rules (forward to external number)
- [ ] Time-of-day routing (without business hours schedule)
- [ ] Caller ID whitelisting/blacklisting
- [ ] Custom IVR menus
- [ ] SMS routing and management
- [ ] Phone number analytics (call volume, peak times)
- [ ] Automatic failover to backup routing
- [ ] Phone number pooling (multiple numbers share same routing)
- [ ] Integration with external phone number providers
- [ ] Phone number reservation/porting workflow

---

## 16. Notes and Considerations

### Performance
- Index on `phone_number` ensures fast webhook lookups
- Index on `(organization_id, status)` optimizes filtered queries
- Eager load related resources (extension, ring_group, etc.) to avoid N+1 queries

### Security
- Phone numbers are globally unique (prevent conflicts)
- Organization scoping prevents cross-tenant access
- Only Owner and PBX Admin can modify phone numbers
- Webhook validation prevents spoofing

### Scalability
- Phone number routing is stateless (webhook → database lookup → CXML response)
- Redis caching can be added for frequently accessed phone numbers
- Cloudonix handles all telephony infrastructure

### User Experience
- Phone numbers formatted for readability in UI (+1 (212) 555-1234)
- Clear error messages when routing targets are invalid
- Real-time validation prevents configuration errors
- Easy switching between routing types without data loss

---

**End of Specification**
