# Business Hours Feature Specification

## 1. Overview

The Business Hours feature allows organizations to define when they are open for business and configure different call routing behaviors for business hours vs. after-hours. This enables automated call handling based on time-of-day and day-of-week schedules, with support for holidays and special exceptions.

### Key Features
- Define multiple business hours schedules per organization
- Configure time ranges for each day of the week
- Set timezone for schedule interpretation
- Create holiday/exception dates with custom routing
- Associate schedules with DIDs for time-based routing
- Support for "open 24/7" and "closed all day" configurations
- Visual calendar view for schedule management

### Use Cases
- **Standard Business**: Route calls to receptionists during 9-5, voicemail after hours
- **Multi-Department**: Different schedules for sales (8-6) vs. support (24/7)
- **Seasonal Hours**: Adjust hours for holidays, summer hours, etc.
- **Multi-Timezone**: Multiple locations with different local hours
- **On-Call Rotation**: Route after-hours calls to on-call extension

---

## 2. Data Model

### Business Hours Schedule
```typescript
interface BusinessHoursSchedule {
  id: string;                           // UUID
  organization_id: string;              // UUID - tenant isolation
  name: string;                         // "Main Office Hours"
  description?: string;                 // Optional detailed description
  timezone: string;                     // IANA timezone (e.g., "America/New_York")
  status: 'active' | 'inactive';       // Schedule status

  // Weekly schedule
  schedule: WeeklySchedule;

  // Exception dates (holidays, special closures)
  exceptions: ExceptionDate[];

  // Metadata
  created_at: string;                   // ISO 8601
  updated_at: string;                   // ISO 8601
  created_by: string;                   // User ID
  updated_by?: string;                  // User ID
}

interface WeeklySchedule {
  monday: DaySchedule;
  tuesday: DaySchedule;
  wednesday: DaySchedule;
  thursday: DaySchedule;
  friday: DaySchedule;
  saturday: DaySchedule;
  sunday: DaySchedule;
}

interface DaySchedule {
  enabled: boolean;                     // true = open, false = closed all day
  time_ranges: TimeRange[];            // Empty array = closed, even if enabled=true
}

interface TimeRange {
  id: string;                           // UUID for frontend management
  start_time: string;                   // "09:00" (24-hour format HH:mm)
  end_time: string;                     // "17:00" (24-hour format HH:mm)
}

interface ExceptionDate {
  id: string;                           // UUID
  date: string;                         // "2025-12-25" (ISO 8601 date)
  name: string;                         // "Christmas Day"
  type: 'closed' | 'special_hours';    // Type of exception
  time_ranges?: TimeRange[];           // Only for type='special_hours'
}
```

### DID Business Hours Association
```typescript
interface DidBusinessHours {
  did_number_id: string;                // UUID - foreign key to did_numbers
  business_hours_schedule_id: string;   // UUID - foreign key to business_hours_schedules

  // Routing during business hours
  business_hours_action: RoutingAction;
  business_hours_target?: string;       // Extension ID, Ring Group ID, etc.

  // Routing after hours
  after_hours_action: RoutingAction;
  after_hours_target?: string;          // Extension ID, Ring Group ID, etc.

  // Routing during exceptions (optional, falls back to after_hours if not set)
  exception_action?: RoutingAction;
  exception_target?: string;
}

type RoutingAction =
  | 'extension'           // Route to specific extension
  | 'ring_group'          // Route to ring group
  | 'voicemail'           // Send to voicemail
  | 'announcement'        // Play announcement and hangup
  | 'auto_attendant'      // Future: IVR menu
  | 'hangup';             // Immediate hangup
```

---

## 3. Field Definitions

### Schedule Fields

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| `name` | string | Yes | 2-100 chars, unique per org | Descriptive name for the schedule |
| `description` | string | No | 0-500 chars | Optional detailed description |
| `timezone` | string | Yes | Valid IANA timezone | Timezone for interpreting times |
| `status` | enum | Yes | 'active' or 'inactive' | Whether schedule is currently in use |

### Time Range Fields

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| `start_time` | string | Yes | HH:mm format, 00:00-23:59 | Opening time in 24-hour format |
| `end_time` | string | Yes | HH:mm format, 00:00-23:59 | Closing time in 24-hour format |

### Time Range Rules
- `end_time` must be after `start_time` (no overnight ranges like "22:00-02:00")
- For overnight operations, create two ranges: "00:00-02:00" and "22:00-23:59"
- Ranges can overlap (e.g., "09:00-13:00" and "12:00-17:00" = open 09:00-17:00)
- Maximum 10 time ranges per day
- Minimum gap between ranges: none required (can be consecutive)

### Exception Date Fields

| Field | Type | Required | Constraints | Description |
|-------|------|----------|-------------|-------------|
| `date` | string | Yes | ISO 8601 date (YYYY-MM-DD) | Date of exception |
| `name` | string | Yes | 2-100 chars | Name/description of exception |
| `type` | enum | Yes | 'closed' or 'special_hours' | Type of exception |
| `time_ranges` | array | Conditional | Required if type='special_hours' | Custom hours for this date |

---

## 4. Business Logic

### Schedule Evaluation Algorithm

When a call arrives at a DID with business hours configured:

1. **Get Current Time**: Use the schedule's timezone to determine current date/time
2. **Check Exceptions**: Look for exception date matching current date
   - If found and type='closed': Use after-hours routing
   - If found and type='special_hours': Evaluate against exception time ranges
   - If not found: Continue to step 3
3. **Check Weekly Schedule**: Get the schedule for current day of week
   - If day is disabled: Use after-hours routing
   - If day has no time ranges: Use after-hours routing
   - If day has time ranges: Continue to step 4
4. **Check Time Ranges**: Evaluate current time against all ranges for the day
   - If current time falls within any range: Use business-hours routing
   - If current time is outside all ranges: Use after-hours routing

### Time Range Overlap Handling
- Overlapping ranges are merged when evaluated
- Ranges like "09:00-13:00" and "12:00-17:00" effectively become "09:00-17:00"
- This allows flexible definition without strict validation

### Timezone Handling
- All times are stored in local time for the configured timezone
- Webhook evaluation uses the schedule's timezone to determine "now"
- No automatic DST handling - times are interpreted as-is in the timezone
- Example: "09:00" in "America/New_York" means 9 AM Eastern (EDT or EST depending on date)

### 24/7 Operation
- To configure 24/7 operation:
  - Set all days to enabled=true
  - Set single time range "00:00-23:59" for each day
  - OR use inactive status and don't associate with DIDs

### Closed All Day
- To configure closed all day:
  - Set enabled=false for the day
  - OR set enabled=true with empty time_ranges array

---

## 5. UI/UX Design

### 5.1 Business Hours List Page

**Route**: `/business-hours`

**Layout**:
```
┌─────────────────────────────────────────────────────────────────┐
│ Business Hours                                    [+ New Schedule]│
├─────────────────────────────────────────────────────────────────┤
│ Manage business hours schedules for time-based call routing     │
│                                                                   │
│ [Search schedules...] [Status: All ▼] [Sort: Name ▼]            │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Name              │ Timezone          │ Status  │ Actions   │ │
│ ├─────────────────────────────────────────────────────────────┤ │
│ │ Main Office Hours │ America/New_York  │ Active  │ Edit Delete│ │
│ │ Mon-Fri 9:00-17:00                                          │ │
│ │ 2 exceptions                                                │ │
│ ├─────────────────────────────────────────────────────────────┤ │
│ │ Support 24/7      │ America/Chicago   │ Active  │ Edit Delete│ │
│ │ Open 24 hours, all days                                     │ │
│ │ No exceptions                                               │ │
│ ├─────────────────────────────────────────────────────────────┤ │
│ │ Summer Hours      │ America/New_York  │ Inactive│ Edit Delete│ │
│ │ Mon-Thu 9:00-18:00, Fri 9:00-15:00                         │ │
│ │ 0 exceptions                                                │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ Showing 1-3 of 3 schedules                                       │
└─────────────────────────────────────────────────────────────────┘
```

**Features**:
- Table displays all schedules with name, timezone, status, and summary
- Summary line shows condensed hours (e.g., "Mon-Fri 9:00-17:00")
- Exception count displayed below summary
- Search by name and description
- Filter by status (all, active, inactive)
- Sort by name, timezone, created date
- Click row to view details in side sheet
- Edit and Delete actions for Owner/PBX Admin

### 5.2 Create/Edit Schedule Dialog

**Trigger**: Click "+ New Schedule" or "Edit" action

**Layout**:
```
┌─────────────────────────────────────────────────────────────────┐
│ Create Business Hours Schedule                            [X]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ Basic Information                                                │
│                                                                   │
│ Name *                                                           │
│ [Main Office Hours                                            ]  │
│                                                                   │
│ Description (Optional)                                           │
│ [Standard business hours for main reception                   ]  │
│                                                                   │
│ Timezone *                                                       │
│ [America/New_York                                         ▼]     │
│                                                                   │
│ Status                                                           │
│ [●] Active  [○] Inactive                                        │
│                                                                   │
│ ─────────────────────────────────────────────────────────────   │
│                                                                   │
│ Weekly Schedule                                                  │
│                                                                   │
│ ┌─ Monday ────────────────────────────────────────────────────┐ │
│ │ [✓] Open  [○] Closed                                        │ │
│ │                                                              │ │
│ │ Time Ranges:                                                │ │
│ │   [09:00] to [17:00]                               [X]      │ │
│ │   [+ Add Time Range]                                        │ │
│ └──────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ ┌─ Tuesday ───────────────────────────────────────────────────┐ │
│ │ [✓] Open  [○] Closed                                        │ │
│ │                                                              │ │
│ │ Time Ranges:                                                │ │
│ │   [09:00] to [17:00]                               [X]      │ │
│ │   [+ Add Time Range]                                        │ │
│ └──────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ ... (similar for Wed-Sun) ...                                    │
│                                                                   │
│ [Copy Hours To...]  (Quick action to copy to multiple days)     │
│                                                                   │
│ ─────────────────────────────────────────────────────────────   │
│                                                                   │
│ Exception Dates                                                  │
│                                                                   │
│ ┌──────────────────────────────────────────────────────────────┐│
│ │ Date       │ Name          │ Type           │ Actions        ││
│ ├──────────────────────────────────────────────────────────────┤│
│ │ 2025-12-25 │ Christmas Day │ Closed         │ Edit  Delete   ││
│ │ 2025-07-04 │ Independence  │ Special Hours  │ Edit  Delete   ││
│ │            │               │ 10:00-14:00    │                ││
│ └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│ [+ Add Exception Date]                                           │
│                                                                   │
├─────────────────────────────────────────────────────────────────┤
│                                         [Cancel]  [Save Schedule]│
└─────────────────────────────────────────────────────────────────┘
```

**Features**:
- Separate sections for basic info, weekly schedule, and exceptions
- Each day has open/closed toggle
- Add multiple time ranges per day with remove button
- Time inputs use time picker (dropdown or native)
- "Copy Hours To..." button opens dialog to select multiple days
- Exception dates section with inline table
- Add exception opens sub-dialog (see 5.3)
- Validation prevents saving invalid configurations
- Scrollable dialog for long schedules

### 5.3 Add Exception Date Sub-Dialog

**Trigger**: Click "+ Add Exception Date"

**Layout**:
```
┌──────────────────────────────────────────────────┐
│ Add Exception Date                         [X]   │
├──────────────────────────────────────────────────┤
│                                                   │
│ Date *                                           │
│ [📅 Select date...                             ] │
│                                                   │
│ Name *                                           │
│ [Christmas Day                                 ] │
│                                                   │
│ Type *                                           │
│ [●] Closed All Day                               │
│ [○] Special Hours                                │
│                                                   │
│ ┌─ Special Hours ─────────────────────────────┐ │
│ │ (Only applicable if Special Hours selected) │ │
│ │                                              │ │
│ │ Time Ranges:                                │ │
│ │   [10:00] to [14:00]               [X]      │ │
│ │   [+ Add Time Range]                        │ │
│ └──────────────────────────────────────────────┘ │
│                                                   │
├──────────────────────────────────────────────────┤
│                          [Cancel]  [Add Exception]│
└──────────────────────────────────────────────────┘
```

**Features**:
- Date picker prevents selecting past dates
- Type toggle shows/hides special hours section
- Special hours use same time range UI as weekly schedule
- Validation prevents duplicate dates

### 5.4 Schedule Detail Sheet

**Trigger**: Click on schedule row in table

**Layout**:
```
┌─────────────────────────────────────────────────────┐
│ [<] Main Office Hours                         [Edit]│
├─────────────────────────────────────────────────────┤
│                                                      │
│ Basic Information                                    │
│ Status: Active                                       │
│ Timezone: America/New_York (EST/EDT)                │
│ Description: Standard business hours for main...    │
│                                                      │
│ Created: Dec 27, 2025 by John Smith                 │
│ Updated: Dec 27, 2025 by Jane Doe                   │
│                                                      │
│ ──────────────────────────────────────────────────  │
│                                                      │
│ Weekly Schedule                                      │
│                                                      │
│ ┌─ Visual Week View ──────────────────────────────┐ │
│ │   Mon    Tue    Wed    Thu    Fri    Sat    Sun │ │
│ │ ┌────┐ ┌────┐ ┌────┐ ┌────┐ ┌────┐ ┌────┐ ┌────┐││
│ │ │9-5 │ │9-5 │ │9-5 │ │9-5 │ │9-5 │ │    │ │    │││
│ │ └────┘ └────┘ └────┘ └────┘ └────┘ │Clsd│ │Clsd│││
│ │                                      └────┘ └────┘││
│ └──────────────────────────────────────────────────┘ │
│                                                      │
│ Detailed Hours:                                      │
│ • Monday-Friday: 09:00-17:00                        │
│ • Saturday-Sunday: Closed                           │
│                                                      │
│ ──────────────────────────────────────────────────  │
│                                                      │
│ Exception Dates (2)                                  │
│                                                      │
│ • Dec 25, 2025 - Christmas Day (Closed)             │
│ • Jul 4, 2025 - Independence Day (10:00-14:00)      │
│                                                      │
│ ──────────────────────────────────────────────────  │
│                                                      │
│ Associated DIDs (3)                                  │
│                                                      │
│ • +1-555-0100 (Main Line)                           │
│   Business Hours: Main Reception (Ring Group)       │
│   After Hours: Voicemail                            │
│                                                      │
│ • +1-555-0101 (Support)                             │
│   Business Hours: Support Team (Ring Group)         │
│   After Hours: Emergency On-Call (Extension)        │
│                                                      │
│ • +1-555-0102 (Sales)                               │
│   Business Hours: Sales Extension (Ext 100)         │
│   After Hours: Voicemail                            │
│                                                      │
└─────────────────────────────────────────────────────┘
```

**Features**:
- Read-only view of schedule details
- Visual week calendar showing open/closed status
- Grouped detailed hours (e.g., "Monday-Friday: 09:00-17:00")
- List of exception dates with types
- List of DIDs using this schedule with routing details
- Edit button opens edit dialog
- Back button returns to list

### 5.5 DID Configuration Integration

**Location**: DID Edit Dialog (existing)

**New Section**: "Business Hours Routing"

```
┌─────────────────────────────────────────────────────┐
│ Business Hours Routing                               │
│                                                      │
│ Schedule                                             │
│ [Main Office Hours                             ▼]   │
│ [○] No business hours routing (route same always)   │
│                                                      │
│ ┌─ Business Hours ──────────────────────────────────┐│
│ │ Route calls during business hours to:            ││
│ │                                                   ││
│ │ Action * [Ring Group                        ▼]   ││
│ │ Target * [Main Reception                    ▼]   ││
│ └────────────────────────────────────────────────────┘│
│                                                      │
│ ┌─ After Hours ─────────────────────────────────────┐│
│ │ Route calls outside business hours to:           ││
│ │                                                   ││
│ │ Action * [Voicemail                         ▼]   ││
│ │ Target   [General Voicemail                 ▼]   ││
│ └────────────────────────────────────────────────────┘│
│                                                      │
│ ┌─ Exception Days (Optional) ───────────────────────┐│
│ │ Route calls on exception dates to:               ││
│ │ [✓] Use after-hours routing                      ││
│ │ [○] Use custom routing:                          ││
│ │     Action [                                ▼]   ││
│ │     Target [                                ▼]   ││
│ └────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────┘
```

**Features**:
- Optional section, enabled when schedule is selected
- Separate configuration for business hours, after hours, exceptions
- Action dropdown: Extension, Ring Group, Voicemail, Announcement, Hangup
- Target dropdown populated based on action (extensions, ring groups, etc.)
- Exception routing defaults to after-hours but can be customized
- Visual preview showing current status (e.g., "Currently: Business Hours")

### 5.6 Quick Copy Hours Dialog

**Trigger**: Click "Copy Hours To..." button in create/edit dialog

**Layout**:
```
┌────────────────────────────────────────────┐
│ Copy Hours To Multiple Days          [X]   │
├────────────────────────────────────────────┤
│                                             │
│ Copy hours from: Monday                     │
│                                             │
│ Current hours: 09:00-17:00                  │
│                                             │
│ Copy to:                                    │
│ [✓] Tuesday                                 │
│ [✓] Wednesday                               │
│ [✓] Thursday                                │
│ [✓] Friday                                  │
│ [○] Saturday                                │
│ [○] Sunday                                  │
│                                             │
│ [Select All]  [Select None]  [Weekdays]    │
│                                             │
├────────────────────────────────────────────┤
│                     [Cancel]  [Copy Hours] │
└────────────────────────────────────────────┘
```

**Features**:
- Select source day from dropdown
- Checkboxes for each destination day
- Quick select buttons for common patterns
- Preserves existing hours on unselected days

---

## 6. Workflows

### 6.1 Create New Business Hours Schedule

1. User clicks "+ New Schedule" button
2. Dialog opens with empty form
3. User fills in:
   - Name (required)
   - Description (optional)
   - Timezone (required, defaults to org timezone)
   - Status (defaults to Active)
4. User configures weekly schedule:
   - For each day: toggle Open/Closed
   - For open days: add time ranges
   - Use "Copy Hours To..." for repetitive schedules
5. User adds exception dates (optional):
   - Click "+ Add Exception Date"
   - Fill in date, name, type
   - Configure special hours if applicable
6. User clicks "Save Schedule"
7. System validates all inputs
8. If valid: Save and show success message
9. If invalid: Show validation errors inline
10. Return to list page with new schedule visible

### 6.2 Edit Existing Schedule

1. User clicks "Edit" action on schedule row
2. Edit dialog opens pre-filled with existing data
3. User modifies any fields
4. User clicks "Save Schedule"
5. System validates changes
6. If schedule is associated with DIDs: Show warning about affecting active routing
7. User confirms changes
8. System saves updates
9. Return to list with updated schedule

### 6.3 Delete Schedule

1. User clicks "Delete" action on schedule row
2. System checks if schedule is associated with any DIDs
3. If associated:
   - Show warning: "This schedule is used by X DIDs. Deleting will remove business hours routing from those DIDs."
   - List affected DIDs
   - Require confirmation
4. If not associated:
   - Show simple confirmation: "Delete Main Office Hours?"
5. User confirms
6. System deletes schedule (and removes DID associations)
7. Show success message
8. Refresh list

### 6.4 Configure DID with Business Hours

1. User navigates to DIDs page
2. User clicks "Edit" on a DID
3. In edit dialog, user scrolls to "Business Hours Routing" section
4. User selects a schedule from dropdown
5. Section expands to show routing configuration
6. User configures:
   - Business hours action and target
   - After hours action and target
   - Exception routing (optional)
7. User clicks "Save"
8. System validates configuration
9. System saves DID with business hours association
10. Future calls to this DID will be routed based on schedule

### 6.5 Test/Verify Schedule

**Visual Indicator on List Page**:
- Each schedule shows "Current Status" badge:
  - 🟢 "Open" (currently within business hours)
  - 🔴 "Closed" (currently outside business hours)
  - 🟡 "Exception" (currently on exception date)
- Badge updates in real-time (refreshes every minute)

**Detail Sheet Preview**:
- Shows "Current Status" at top with current time in schedule's timezone
- Shows next status change (e.g., "Closes in 2 hours" or "Opens tomorrow at 9:00 AM")
- Allows manual time entry to preview status at specific time

---

## 7. Role-Based Permissions

| Role | View | Create | Edit | Delete | Associate with DID |
|------|------|--------|------|--------|--------------------|
| Owner | ✅ | ✅ | ✅ | ✅ | ✅ |
| PBX Admin | ✅ | ✅ | ✅ | ✅ | ✅ |
| Agent | ✅ | ❌ | ❌ | ❌ | ❌ |

**Permission Enforcement**:
- Backend: Policy-based authorization in Laravel
- Frontend: Hide/disable UI elements based on user role
- API: Return 403 Forbidden for unauthorized actions

---

## 8. Validation Rules

### Schedule Validation

| Field | Validation |
|-------|------------|
| `name` | Required, 2-100 chars, unique within organization |
| `description` | Optional, max 500 chars |
| `timezone` | Required, must be valid IANA timezone |
| `status` | Required, must be 'active' or 'inactive' |

### Time Range Validation

| Rule | Description |
|------|-------------|
| Format | HH:mm (24-hour format) |
| Range | 00:00 to 23:59 |
| End after start | end_time must be after start_time (no overnight ranges) |
| Max ranges per day | 10 time ranges maximum |
| Overlaps | Allowed (ranges will be merged during evaluation) |

### Exception Date Validation

| Field | Validation |
|-------|------------|
| `date` | Required, must be valid date, must not be in the past |
| `name` | Required, 2-100 chars |
| `type` | Required, must be 'closed' or 'special_hours' |
| `time_ranges` | Required if type='special_hours', must follow time range validation |
| Duplicates | Cannot add same date twice in same schedule |

### DID Association Validation

| Field | Validation |
|-------|------------|
| `schedule_id` | Must exist and belong to same organization |
| `business_hours_action` | Required, must be valid routing action |
| `business_hours_target` | Required if action needs target (extension, ring_group) |
| `after_hours_action` | Required, must be valid routing action |
| `after_hours_target` | Required if action needs target |
| Target existence | Extension/Ring Group/etc. must exist and belong to same org |

---

## 9. API Specifications

### 9.1 List Business Hours Schedules

```http
GET /api/v1/business-hours
```

**Query Parameters**:
- `search` (string, optional): Search by name or description
- `status` (string, optional): Filter by status ('active', 'inactive')
- `page` (integer, optional): Page number for pagination
- `per_page` (integer, optional): Results per page (max 100)
- `sort` (string, optional): Sort field ('name', 'created_at', 'updated_at')
- `order` (string, optional): Sort order ('asc', 'desc')

**Response** (200 OK):
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "organization_id": "org-uuid",
      "name": "Main Office Hours",
      "description": "Standard business hours",
      "timezone": "America/New_York",
      "status": "active",
      "schedule": {
        "monday": {
          "enabled": true,
          "time_ranges": [
            {"id": "tr-1", "start_time": "09:00", "end_time": "17:00"}
          ]
        },
        "tuesday": {...},
        "wednesday": {...},
        "thursday": {...},
        "friday": {...},
        "saturday": {"enabled": false, "time_ranges": []},
        "sunday": {"enabled": false, "time_ranges": []}
      },
      "exceptions": [
        {
          "id": "exc-1",
          "date": "2025-12-25",
          "name": "Christmas Day",
          "type": "closed"
        }
      ],
      "current_status": "open",
      "next_change": {
        "timestamp": "2025-12-27T17:00:00-05:00",
        "to_status": "closed",
        "description": "Closes in 2 hours"
      },
      "created_at": "2025-12-27T10:00:00Z",
      "updated_at": "2025-12-27T10:00:00Z",
      "created_by": "user-uuid",
      "updated_by": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 3,
    "last_page": 1,
    "from": 1,
    "to": 3
  }
}
```

### 9.2 Get Single Schedule

```http
GET /api/v1/business-hours/{id}
```

**Response** (200 OK):
```json
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "organization_id": "org-uuid",
    "name": "Main Office Hours",
    "description": "Standard business hours",
    "timezone": "America/New_York",
    "status": "active",
    "schedule": {...},
    "exceptions": [...],
    "current_status": "open",
    "next_change": {...},
    "associated_dids": [
      {
        "id": "did-uuid",
        "phone_number": "+15550100",
        "name": "Main Line",
        "business_hours_action": "ring_group",
        "business_hours_target": "rg-uuid",
        "after_hours_action": "voicemail"
      }
    ],
    "created_at": "2025-12-27T10:00:00Z",
    "updated_at": "2025-12-27T10:00:00Z",
    "created_by": "user-uuid",
    "updated_by": null
  }
}
```

### 9.3 Create Schedule

```http
POST /api/v1/business-hours
```

**Request Body**:
```json
{
  "name": "Main Office Hours",
  "description": "Standard business hours",
  "timezone": "America/New_York",
  "status": "active",
  "schedule": {
    "monday": {
      "enabled": true,
      "time_ranges": [
        {"start_time": "09:00", "end_time": "17:00"}
      ]
    },
    "tuesday": {...},
    "wednesday": {...},
    "thursday": {...},
    "friday": {...},
    "saturday": {"enabled": false, "time_ranges": []},
    "sunday": {"enabled": false, "time_ranges": []}
  },
  "exceptions": [
    {
      "date": "2025-12-25",
      "name": "Christmas Day",
      "type": "closed"
    }
  ]
}
```

**Response** (201 Created):
```json
{
  "message": "Business hours schedule created successfully.",
  "data": {...}
}
```

**Error Response** (422 Unprocessable Entity):
```json
{
  "error": "Validation Failed",
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name has already been taken."],
    "schedule.monday.time_ranges.0.end_time": ["End time must be after start time."]
  }
}
```

### 9.4 Update Schedule

```http
PUT /api/v1/business-hours/{id}
```

**Request Body**: Same as create

**Response** (200 OK):
```json
{
  "message": "Business hours schedule updated successfully.",
  "data": {...}
}
```

### 9.5 Delete Schedule

```http
DELETE /api/v1/business-hours/{id}
```

**Response** (204 No Content)

**Error Response** (409 Conflict):
```json
{
  "error": "Cannot Delete",
  "message": "This schedule is associated with 3 DIDs. Remove associations before deleting.",
  "associated_dids": [
    {"id": "did-1", "phone_number": "+15550100", "name": "Main Line"},
    {"id": "did-2", "phone_number": "+15550101", "name": "Support"},
    {"id": "did-3", "phone_number": "+15550102", "name": "Sales"}
  ]
}
```

### 9.6 Evaluate Schedule at Time

```http
POST /api/v1/business-hours/{id}/evaluate
```

**Request Body**:
```json
{
  "timestamp": "2025-12-27T15:30:00"  // Optional, defaults to now
}
```

**Response** (200 OK):
```json
{
  "schedule_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2025-12-27T15:30:00-05:00",
  "status": "open",
  "reason": "Within business hours (Monday 09:00-17:00)",
  "next_change": {
    "timestamp": "2025-12-27T17:00:00-05:00",
    "to_status": "closed",
    "description": "Closes in 1 hour 30 minutes"
  }
}
```

### 9.7 Get Schedule for DID (Internal API)

Used by webhook handler to get business hours for incoming call routing.

```http
GET /api/v1/internal/dids/{did_number_id}/business-hours
```

**Response** (200 OK):
```json
{
  "has_business_hours": true,
  "schedule": {...},
  "current_status": "open",
  "routing": {
    "action": "ring_group",
    "target": "rg-uuid",
    "target_details": {
      "id": "rg-uuid",
      "name": "Main Reception",
      "type": "ring_group"
    }
  }
}
```

---

## 10. Database Schema

### Table: `business_hours_schedules`

```sql
CREATE TABLE business_hours_schedules (
    id CHAR(36) PRIMARY KEY,
    organization_id CHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    timezone VARCHAR(100) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    -- Weekly schedule stored as JSON
    schedule JSON NOT NULL,

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by CHAR(36),
    updated_by CHAR(36),

    -- Indexes
    INDEX idx_organization (organization_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_org_name (organization_id, name),

    -- Foreign keys
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
```

### Table: `business_hours_exceptions`

```sql
CREATE TABLE business_hours_exceptions (
    id CHAR(36) PRIMARY KEY,
    business_hours_schedule_id CHAR(36) NOT NULL,
    date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('closed', 'special_hours') NOT NULL,

    -- Time ranges for special_hours (JSON array)
    time_ranges JSON,

    -- Indexes
    INDEX idx_schedule (business_hours_schedule_id),
    INDEX idx_date (date),
    UNIQUE KEY unique_schedule_date (business_hours_schedule_id, date),

    -- Foreign keys
    FOREIGN KEY (business_hours_schedule_id)
        REFERENCES business_hours_schedules(id)
        ON DELETE CASCADE
);
```

### Table: `did_business_hours` (junction table)

```sql
CREATE TABLE did_business_hours (
    did_number_id CHAR(36) PRIMARY KEY,
    business_hours_schedule_id CHAR(36) NOT NULL,

    -- Business hours routing
    business_hours_action VARCHAR(50) NOT NULL,
    business_hours_target CHAR(36),

    -- After hours routing
    after_hours_action VARCHAR(50) NOT NULL,
    after_hours_target CHAR(36),

    -- Exception routing (optional, falls back to after_hours if null)
    exception_action VARCHAR(50),
    exception_target CHAR(36),

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_schedule (business_hours_schedule_id),

    -- Foreign keys
    FOREIGN KEY (did_number_id) REFERENCES did_numbers(id) ON DELETE CASCADE,
    FOREIGN KEY (business_hours_schedule_id)
        REFERENCES business_hours_schedules(id)
        ON DELETE CASCADE
);
```

### JSON Schema for `schedule` field

```json
{
  "monday": {
    "enabled": true,
    "time_ranges": [
      {"start_time": "09:00", "end_time": "17:00"}
    ]
  },
  "tuesday": {...},
  "wednesday": {...},
  "thursday": {...},
  "friday": {...},
  "saturday": {...},
  "sunday": {...}
}
```

### JSON Schema for `time_ranges` in exceptions

```json
[
  {"start_time": "10:00", "end_time": "14:00"}
]
```

---

## 11. Integration Points

### 11.1 Webhook Handler Integration

**File**: `app/Http/Controllers/Webhooks/CloudonixWebhookController.php`

**Changes Required**:
- In `callInitiated()` method, after DID lookup:
  1. Check if DID has business hours configured
  2. If yes: Evaluate current time against schedule
  3. Determine status: 'business_hours', 'after_hours', or 'exception'
  4. Get appropriate routing configuration based on status
  5. Generate CXML using determined routing

**Pseudocode**:
```php
// After finding $didNumber
$businessHoursConfig = $didNumber->businessHours;

if ($businessHoursConfig) {
    $schedule = $businessHoursConfig->schedule;
    $status = $this->evaluateBusinessHours($schedule);

    if ($status === 'business_hours') {
        $action = $businessHoursConfig->business_hours_action;
        $target = $businessHoursConfig->business_hours_target;
    } elseif ($status === 'exception' && $businessHoursConfig->exception_action) {
        $action = $businessHoursConfig->exception_action;
        $target = $businessHoursConfig->exception_target;
    } else {
        $action = $businessHoursConfig->after_hours_action;
        $target = $businessHoursConfig->after_hours_target;
    }

    $cxml = $this->generateRoutingCxml($action, $target);
} else {
    // No business hours configured, use DID's default routing
    $cxml = $this->routingService->routeInboundCall(...);
}
```

### 11.2 CallRoutingService Integration

**File**: `app/Services/CallRouting/CallRoutingService.php`

**New Method**:
```php
public function evaluateBusinessHours(
    BusinessHoursSchedule $schedule,
    ?Carbon $timestamp = null
): string {
    // Returns: 'business_hours', 'after_hours', or 'exception'
}
```

### 11.3 DID Management Integration

**Files**:
- `app/Http/Controllers/Api/DidNumbersController.php`
- `frontend/src/pages/DIDs.tsx`

**Changes**:
- Add business hours association fields to DID create/update forms
- Display business hours status in DID list
- Show current routing in DID detail view

### 11.4 Dashboard Integration

**File**: `frontend/src/pages/Dashboard.tsx`

**New Widget**: "Business Hours Status"
- Shows current status for each schedule
- Quick view of next schedule changes
- Links to business hours management page

---

## 12. Edge Cases and Special Handling

### 12.1 Overlapping Time Ranges
**Scenario**: User creates ranges "09:00-13:00" and "12:00-17:00" for same day
**Handling**: Treat as continuous range "09:00-17:00" during evaluation
**UI**: Show warning in UI but allow saving

### 12.2 Overnight Hours
**Scenario**: Business operates "22:00-02:00" (overnight)
**Handling**: Require two ranges: "00:00-02:00" and "22:00-23:59"
**UI**: Show helper text explaining overnight range splitting

### 12.3 Timezone Changes (DST)
**Scenario**: Schedule in "America/New_York" during DST transition
**Handling**: Times are always local time, no automatic adjustment
**Example**: "09:00" means 9 AM local time year-round (DST-aware)
**Note**: Carbon/PHP handles DST automatically when parsing times in timezone

### 12.4 Past Exception Dates
**Scenario**: Schedule has exception for 2024-12-25, now it's 2025
**Handling**: Past exceptions are ignored during evaluation
**Cleanup**: Provide admin tool to remove old exceptions (future enhancement)
**UI**: Show warning for exceptions >1 year in past

### 12.5 Schedule Deletion with DID Associations
**Scenario**: User tries to delete schedule used by 5 DIDs
**Handling**: Show warning with list of affected DIDs
**Options**:
1. Block deletion (force user to remove associations first)
2. Allow deletion with confirmation (removes associations)
**Recommendation**: Use option 2 (allow with confirmation)

### 12.6 Concurrent Edits
**Scenario**: Two admins edit same schedule simultaneously
**Handling**: Last write wins (optimistic locking)
**Improvement**: Show warning if schedule was updated since loaded (compare `updated_at`)

### 12.7 Invalid Timezone
**Scenario**: Organization moves, needs to change timezone
**Handling**: Allow timezone change on schedule
**Warning**: Show impact - "All time ranges will be interpreted in new timezone"
**Validation**: Ensure timezone is valid IANA timezone using PHP timezone functions

### 12.8 24/7 Operation
**Scenario**: Support team operates 24/7, needs schedule for consistency
**Handling**: Allow creating schedule with all days "00:00-23:59"
**Alternative**: Allow DID to not have business hours (simpler for true 24/7)

### 12.9 No Schedule Days Enabled
**Scenario**: User creates schedule but disables all days
**Validation**: Warn but allow (might be template for future use)
**Effect**: If associated with DID, always uses after-hours routing

### 12.10 Exception on Day Already Closed
**Scenario**: Exception date falls on Sunday, which is already closed
**Handling**: Exception takes precedence (could be special_hours for normally closed day)
**Use Case**: Black Friday hours (Friday after Thanksgiving) - normally closed but opening for event

---

## 13. Testing Requirements

### 13.1 Unit Tests

**Schedule Evaluation Logic**:
- Test time within range
- Test time outside range
- Test overlapping ranges
- Test multiple ranges per day
- Test exception date (closed)
- Test exception date (special hours)
- Test timezone conversion
- Test DST handling
- Test edge cases (midnight, 23:59)

**Validation**:
- Test required fields
- Test unique name per organization
- Test valid timezone
- Test time format validation
- Test end_time after start_time
- Test max ranges per day
- Test duplicate exception dates

### 13.2 Feature Tests

**API Endpoints**:
- Test list schedules with filters
- Test create schedule with valid data
- Test create schedule with invalid data
- Test update schedule
- Test delete schedule without associations
- Test delete schedule with associations
- Test evaluate schedule at specific time
- Test DID association creation
- Test DID association update

**Authorization**:
- Test Owner can CRUD schedules
- Test PBX Admin can CRUD schedules
- Test Agent cannot create/edit/delete
- Test tenant isolation (org A can't see org B schedules)

**Integration**:
- Test webhook routing with business hours
- Test webhook routing after hours
- Test webhook routing on exception date
- Test DID without business hours (default routing)

### 13.3 Frontend Tests

**Component Tests**:
- Test schedule list rendering
- Test create dialog validation
- Test edit dialog pre-filling
- Test time range add/remove
- Test exception date add/remove
- Test copy hours functionality

**Integration Tests**:
- Test create schedule workflow end-to-end
- Test DID association workflow
- Test delete with associations warning
- Test search and filter functionality

### 13.4 Manual QA Checklist

- [ ] Create schedule with various time configurations
- [ ] Test timezone selection with different timezones
- [ ] Add multiple exception dates
- [ ] Test special hours exception
- [ ] Copy hours to multiple days
- [ ] Associate schedule with DID
- [ ] Test current status indicator accuracy
- [ ] Verify routing changes based on time
- [ ] Test delete with/without DID associations
- [ ] Test permission enforcement for different roles
- [ ] Test responsive UI on mobile devices
- [ ] Test time picker usability
- [ ] Verify validation messages display correctly

---

## 14. Performance Considerations

### 14.1 Schedule Evaluation Caching

**Problem**: Every inbound call requires schedule evaluation
**Solution**: Cache evaluation results with 1-minute TTL
**Cache Key**: `business_hours:eval:{schedule_id}:{timestamp_minute}`
**Example**: `business_hours:eval:550e8400:2025-12-27-15-30`

**Benefits**:
- Reduces database queries for schedules
- Reduces timezone conversion calculations
- Multiple calls in same minute use cached result

**Implementation**:
```php
$cacheKey = sprintf(
    'business_hours:eval:%s:%s',
    $schedule->id,
    now($schedule->timezone)->format('Y-m-d-H-i')
);

$status = Cache::remember($cacheKey, 60, function () use ($schedule) {
    return $this->evaluateSchedule($schedule);
});
```

### 14.2 Query Optimization

**List Page**:
- Paginate results (25 per page)
- Lazy load associated DIDs (only load when detail sheet opened)
- Index on `organization_id` and `status`

**Webhook Handler**:
- Eager load business hours relationship with DID query
- Single query to get DID + schedule + exceptions

**Detail View**:
- Eager load all relationships (schedule, exceptions, associated DIDs)
- Use single query with joins

### 14.3 JSON Column Queries

**Avoid**:
- Querying/filtering on JSON `schedule` column
- Use separate columns for searchable attributes

**Prefer**:
- Filter by `status`, `timezone`, `name` (indexed columns)
- Load full schedule only when needed

### 14.4 Exception Date Queries

**Optimization**:
- Index on `date` column in `business_hours_exceptions`
- Query only exceptions >= current date
- Consider archiving old exceptions (>1 year) to separate table

---

## 15. Future Enhancements

### Phase 2 Enhancements

1. **Schedule Templates**
   - Pre-defined templates (9-5 Weekdays, 24/7, Retail Hours, etc.)
   - Save custom schedules as templates
   - Share templates within organization

2. **Bulk Exception Management**
   - Import holidays from calendar (US Holidays, Regional Holidays)
   - Export/import exception dates
   - Recurring exceptions (e.g., "Every last Monday")

3. **Schedule Analytics**
   - Call volume by business hours vs. after hours
   - Missed call analysis during closed hours
   - Optimization suggestions based on call patterns

4. **Advanced Routing**
   - Different routing for different time windows (morning vs. afternoon)
   - Overflow routing (if primary target unavailable during business hours)
   - Priority routing for VIP callers regardless of hours

5. **Multi-Schedule Support per DID**
   - Primary schedule + override schedules
   - Schedule priority/precedence rules
   - Season-based schedule switching

### Phase 3 Enhancements

6. **Schedule Override**
   - Temporary schedule override (emergency closure, extended hours)
   - Override with expiration
   - Manual "Open Now" / "Close Now" toggle

7. **Schedule Approval Workflow**
   - Require Owner approval for schedule changes
   - Change history with audit trail
   - Rollback to previous schedule version

8. **Integration with External Calendars**
   - Sync with Google Calendar for holidays
   - Sync with Outlook for company events
   - Import iCal feeds for closures

9. **SMS Notifications**
   - Notify admins of schedule changes
   - Send schedule reminders
   - Alert on manual overrides

10. **Mobile App**
    - View current schedule status
    - Toggle manual overrides
    - Receive schedule alerts

---

## 16. Security Considerations

### Data Security
- All schedules are tenant-scoped (organization_id)
- Prevent cross-tenant access in all queries
- Validate timezone strings to prevent injection
- Sanitize all user inputs

### Authorization
- Policy-based access control in Laravel
- Frontend role checks for UI elements
- API returns 403 for unauthorized actions
- Audit log for schedule modifications

### Input Validation
- Validate time format (HH:mm)
- Validate time ranges (end after start)
- Validate timezone against PHP timezone list
- Prevent SQL injection in JSON queries
- Limit schedule name length to prevent DoS

### Rate Limiting
- Apply rate limiting to schedule CRUD endpoints
- Separate rate limit for evaluation endpoint (higher limit)
- Prevent abuse of create/delete operations

---

## 17. Documentation Requirements

### User Documentation

1. **Getting Started Guide**
   - What are business hours schedules?
   - Why use business hours routing?
   - Step-by-step: Create your first schedule

2. **How-To Guides**
   - Configure standard 9-5 weekday hours
   - Set up 24/7 operation
   - Add holiday exceptions
   - Configure different routing for different times

3. **Reference Documentation**
   - All fields and their meanings
   - Routing action types explained
   - Timezone selection guide
   - Time range rules and limitations

4. **Troubleshooting**
   - Calls not routing as expected
   - Schedule not taking effect
   - Timezone confusion
   - Exception date not working

### Developer Documentation

1. **API Reference**
   - Complete endpoint documentation
   - Request/response examples
   - Error codes and meanings
   - Authentication requirements

2. **Database Schema**
   - Table relationships
   - JSON schema documentation
   - Index purposes
   - Foreign key constraints

3. **Integration Guide**
   - How to integrate with webhook handler
   - How to evaluate schedules programmatically
   - Caching strategy
   - Performance best practices

4. **Testing Guide**
   - How to write schedule evaluation tests
   - How to mock time for testing
   - Timezone testing strategies
   - Integration test examples

---

## Appendix A: Sample Schedules

### A.1 Standard Office (9-5 Weekdays)
```json
{
  "name": "Standard Office Hours",
  "timezone": "America/New_York",
  "schedule": {
    "monday": {"enabled": true, "time_ranges": [{"start_time": "09:00", "end_time": "17:00"}]},
    "tuesday": {"enabled": true, "time_ranges": [{"start_time": "09:00", "end_time": "17:00"}]},
    "wednesday": {"enabled": true, "time_ranges": [{"start_time": "09:00", "end_time": "17:00"}]},
    "thursday": {"enabled": true, "time_ranges": [{"start_time": "09:00", "end_time": "17:00"}]},
    "friday": {"enabled": true, "time_ranges": [{"start_time": "09:00", "end_time": "17:00"}]},
    "saturday": {"enabled": false, "time_ranges": []},
    "sunday": {"enabled": false, "time_ranges": []}
  }
}
```

### A.2 Retail Hours (Extended Weekends)
```json
{
  "name": "Retail Store Hours",
  "timezone": "America/Chicago",
  "schedule": {
    "monday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "20:00"}]},
    "tuesday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "20:00"}]},
    "wednesday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "20:00"}]},
    "thursday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "20:00"}]},
    "friday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "21:00"}]},
    "saturday": {"enabled": true, "time_ranges": [{"start_time": "10:00", "end_time": "21:00"}]},
    "sunday": {"enabled": true, "time_ranges": [{"start_time": "11:00", "end_time": "18:00"}]}
  }
}
```

### A.3 24/7 Support
```json
{
  "name": "24/7 Support Hours",
  "timezone": "UTC",
  "schedule": {
    "monday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "tuesday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "wednesday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "thursday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "friday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "saturday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]},
    "sunday": {"enabled": true, "time_ranges": [{"start_time": "00:00", "end_time": "23:59"}]}
  }
}
```

### A.4 Split Shift (Lunch Break)
```json
{
  "name": "Office with Lunch Break",
  "timezone": "America/Los_Angeles",
  "schedule": {
    "monday": {
      "enabled": true,
      "time_ranges": [
        {"start_time": "09:00", "end_time": "12:00"},
        {"start_time": "13:00", "end_time": "17:00"}
      ]
    },
    "tuesday": {
      "enabled": true,
      "time_ranges": [
        {"start_time": "09:00", "end_time": "12:00"},
        {"start_time": "13:00", "end_time": "17:00"}
      ]
    },
    // ... similar for Wed-Fri
    "saturday": {"enabled": false, "time_ranges": []},
    "sunday": {"enabled": false, "time_ranges": []}
  }
}
```

---

## Appendix B: Routing Action Details

### Action Types

| Action | Requires Target | Description | Use Case |
|--------|----------------|-------------|----------|
| `extension` | Yes (Extension ID) | Route to specific extension | Direct line to person |
| `ring_group` | Yes (Ring Group ID) | Route to ring group | Team/department line |
| `voicemail` | No (or Extension ID for specific box) | Send to voicemail | After hours, all busy |
| `announcement` | Yes (Announcement ID) | Play message and hangup | Holiday closure message |
| `auto_attendant` | Yes (IVR ID) | Route to IVR menu | Future: Self-service menu |
| `hangup` | No | Immediate disconnect | Emergency closure |

### CXML Generation Examples

**Ring Group**:
```xml
<Response>
  <Dial>
    <Queue>ring_group_uuid</Queue>
  </Dial>
</Response>
```

**Extension**:
```xml
<Response>
  <Dial>
    <User>extension_uuid</User>
  </Dial>
</Response>
```

**Voicemail**:
```xml
<Response>
  <Record
    action="https://api.yourdomain.com/webhooks/voicemail"
    maxLength="300"
    playBeep="true"
  />
</Response>
```

**Announcement**:
```xml
<Response>
  <Say>Thank you for calling. We are currently closed. Please call back during business hours.</Say>
  <Hangup/>
</Response>
```

---

## Document Version

- **Version**: 1.0
- **Date**: 2025-12-27
- **Author**: System Specification
- **Status**: Draft for Review
