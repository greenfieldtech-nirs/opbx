# Business Hours Feature - Functional Specification

## 1. Overview and Purpose

### 1.1 Purpose
The Business Hours feature enables time-based call routing for inbound calls in the PBX system. Organizations can define weekly schedules, holidays, and exceptions to automatically route calls to different destinations based on whether their business is currently open or closed.

### 1.2 Business Value
- **24/7 Call Handling**: Ensures calls are always routed appropriately regardless of business hours
- **Professional Image**: Provides callers with appropriate routing (e.g., to voicemail during off-hours)
- **Operational Efficiency**: Reduces manual call handling and ensures consistent routing
- **Flexibility**: Supports complex scheduling needs including holidays and special events

### 1.3 Scope
**In Scope:**
- Weekly schedule configuration with multiple time ranges per day
- Holiday/exception date management
- Fallback routing configuration for open/closed hours
- Integration with call routing system
- Multi-tenant support with organization isolation
- Real-time status indication

**Out of Scope:**
- Time zone management (assumes UTC for core logic)
- Advanced scheduling features (recurring exceptions)
- Integration with calendar systems
- Automated schedule updates

## 2. Functional Requirements

### 2.1 Business Hours Schedule Management
**FRS-BH-001**: Users must be able to create, read, update, and delete business hours schedules
**FRS-BH-002**: Each schedule must belong to an organization with proper tenant isolation
**FRS-BH-003**: Schedules must have a unique name within the organization
**FRS-BH-004**: Schedules must support active/inactive status
**FRS-BH-005**: Users must be able to duplicate existing schedules

### 2.2 Weekly Schedule Configuration
**FRS-BH-006**: Users must be able to configure business hours for each day of the week
**FRS-BH-007**: Each day must support multiple time ranges (e.g., 9:00-12:00, 13:00-17:00)
**FRS-BH-008**: Days must be individually enabled/disabled
**FRS-BH-009**: Time ranges must be validated to ensure start time < end time
**FRS-BH-010**: Time ranges must not overlap within the same day

### 2.3 Exception Management
**FRS-BH-011**: Users must be able to define exception dates (holidays, special events)
**FRS-BH-012**: Exceptions must support two types: "closed" and "special hours"
**FRS-BH-013**: Closed exceptions route to closed hours configuration
**FRS-BH-014**: Special hours exceptions must support custom time ranges
**FRS-BH-015**: Exception dates must be unique within a schedule

### 2.4 Routing Configuration
**FRS-BH-016**: Schedules must define routing for both open and closed hours
**FRS-BH-017**: Routing destinations must include: extensions, ring groups, voicemail
**FRS-BH-018**: Open hours routing is required
**FRS-BH-019**: Closed hours routing is required
**FRS-BH-020**: Routing configuration must validate destination existence and accessibility

### 2.5 Call Routing Integration
**FRS-BH-021**: DID numbers must support business hours routing type
**FRS-BH-022**: System must determine current business status (open/closed/exception) in real-time
**FRS-BH-023**: Calls must be routed to appropriate destination based on current status
**FRS-BH-024**: Routing decisions must be logged for audit purposes
**FRS-BH-025**: System must handle routing failures gracefully with fallback to busy signal

## 3. User Stories

### 3.1 Administrator Stories
**US-BH-001**: As an administrator, I want to create a business hours schedule so that calls are routed appropriately during business hours
- Given I have admin access
- When I create a schedule with Monday-Friday 9-5 routing to main extension
- Then calls during those hours route to the main extension
- And calls outside those hours route to voicemail

**US-BH-002**: As an administrator, I want to configure holidays so that calls are handled differently on special dates
- Given I have an active business hours schedule
- When I add Christmas Day as a closed exception
- Then calls on Christmas Day route to closed hours configuration regardless of weekday

**US-BH-003**: As an administrator, I want to define special hours for events so that calls are routed to event-specific destinations
- Given I have an active business hours schedule
- When I create a special hours exception for New Year's Eve with routing to party line
- Then calls on New Year's Eve route to the party line extension

**US-BH-004**: As an administrator, I want to duplicate schedules so that I can quickly create variations
- Given I have an existing schedule "Standard Hours"
- When I duplicate it and modify the name to "Summer Hours"
- Then I have two independent schedules with the same configuration

### 3.2 User Stories (Viewing/Using)
**US-BH-005**: As a user, I want to see the current status of business hours so that I know if we're open or closed
- Given I have access to business hours management
- When I view the schedule list
- Then I can see the current status (open/closed/exception) for each schedule

**US-BH-006**: As a user, I want to see which DIDs use business hours routing so that I understand the impact of changes
- Given I have access to DID management
- When I view DID configurations
- Then I can see which ones use business hours routing
- And which specific schedule they reference

## 4. Data Model and Schema

### 4.1 Core Tables

#### business_hours_schedules
```sql
CREATE TABLE business_hours_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    open_hours_action VARCHAR(255) NOT NULL,
    closed_hours_action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,

    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org_status (organization_id, status),
    INDEX idx_deleted (deleted_at)
);
```

#### business_hours_schedule_days
```sql
CREATE TABLE business_hours_schedule_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_hours_schedule_id BIGINT UNSIGNED NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (business_hours_schedule_id) REFERENCES business_hours_schedules(id) ON DELETE CASCADE,
    UNIQUE KEY unique_schedule_day (business_hours_schedule_id, day_of_week),
    INDEX idx_schedule (business_hours_schedule_id)
);
```

#### business_hours_time_ranges
```sql
CREATE TABLE business_hours_time_ranges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_hours_schedule_day_id BIGINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (business_hours_schedule_day_id) REFERENCES business_hours_schedule_days(id) ON DELETE CASCADE,
    INDEX idx_day (business_hours_schedule_day_id)
);
```

#### business_hours_exceptions
```sql
CREATE TABLE business_hours_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_hours_schedule_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('closed', 'special_hours') DEFAULT 'closed',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (business_hours_schedule_id) REFERENCES business_hours_schedules(id) ON DELETE CASCADE,
    INDEX idx_schedule (business_hours_schedule_id),
    INDEX idx_date (date)
);
```

#### business_hours_exception_time_ranges
```sql
CREATE TABLE business_hours_exception_time_ranges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_hours_exception_id BIGINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (business_hours_exception_id) REFERENCES business_hours_exceptions(id) ON DELETE CASCADE,
    INDEX idx_exception (business_hours_exception_id)
);
```

### 4.2 Key Relationships
- BusinessHoursSchedule → Organization (many-to-one)
- BusinessHoursSchedule → BusinessHoursScheduleDay (one-to-many)
- BusinessHoursScheduleDay → BusinessHoursTimeRange (one-to-many)
- BusinessHoursSchedule → BusinessHoursException (one-to-many)
- BusinessHoursException → BusinessHoursExceptionTimeRange (one-to-many)

### 4.3 Data Constraints
- Schedule names must be unique within organization
- Day of week must be unique within schedule
- Exception dates must be unique within schedule
- Time ranges must have start_time < end_time
- Time ranges must not overlap within same day/exception
- Organization isolation enforced at application level

## 5. API Design

### 5.1 REST Endpoints

#### List Business Hours Schedules
```
GET /api/business-hours
Query Parameters:
- per_page (int, default: 20, max: 100)
- page (int, default: 1)
- status (enum: active|inactive)
- search (string)
- sort_by (enum: name|status|created_at|updated_at, default: name)
- sort_order (enum: asc|desc, default: asc)

Response: BusinessHoursScheduleCollection
```

#### Create Business Hours Schedule
```
POST /api/business-hours
Authorization: Bearer {token}
Content-Type: application/json

Body:
{
    "name": "Standard Business Hours",
    "status": "active",
    "open_hours_action": "extension:123",
    "closed_hours_action": "voicemail:main",
    "schedule": {
        "monday": {
            "enabled": true,
            "time_ranges": [
                {"start_time": "09:00", "end_time": "12:00"},
                {"start_time": "13:00", "end_time": "17:00"}
            ]
        },
        "tuesday": { /* ... */ }
        // ... other days
    },
    "exceptions": [
        {
            "date": "2024-12-25",
            "name": "Christmas Day",
            "type": "closed"
        },
        {
            "date": "2024-12-31",
            "name": "New Year's Eve",
            "type": "special_hours",
            "time_ranges": [
                {"start_time": "20:00", "end_time": "23:59"}
            ]
        }
    ]
}

Response: BusinessHoursScheduleResource (201)
```

#### Get Business Hours Schedule
```
GET /api/business-hours/{id}
Authorization: Bearer {token}

Response: BusinessHoursScheduleResource
```

#### Update Business Hours Schedule
```
PUT /api/business-hours/{id}
Authorization: Bearer {token}
Content-Type: application/json

Body: Same as create, all fields optional except relationships are replaced

Response: BusinessHoursScheduleResource
```

#### Delete Business Hours Schedule
```
DELETE /api/business-hours/{id}
Authorization: Bearer {token}

Response: 204 No Content
```

#### Duplicate Business Hours Schedule
```
POST /api/business-hours/{id}/duplicate
Authorization: Bearer {token}

Response: BusinessHoursScheduleResource (201)
```

### 5.2 Request/Response Formats

#### BusinessHoursScheduleResource
```json
{
    "id": 1,
    "organization_id": 1,
    "name": "Standard Business Hours",
    "status": "active",
    "open_hours_action": "extension:123",
    "closed_hours_action": "voicemail:main",
    "current_status": "open",
    "schedule_days": [
        {
            "id": 1,
            "day_of_week": "monday",
            "enabled": true,
            "time_ranges": [
                {
                    "id": 1,
                    "start_time": "09:00:00",
                    "end_time": "12:00:00"
                },
                {
                    "id": 2,
                    "start_time": "13:00:00",
                    "end_time": "17:00:00"
                }
            ]
        }
    ],
    "exceptions": [
        {
            "id": 1,
            "date": "2024-12-25",
            "name": "Christmas Day",
            "type": "closed",
            "time_ranges": []
        }
    ],
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-01T00:00:00Z"
}
```

### 5.3 Validation Rules

#### Schedule Creation/Update
```php
'name' => 'required|string|max:255|unique:business_hours_schedules,name,NULL,id,organization_id,' . $organizationId,
'status' => 'required|in:active,inactive',
'open_hours_action' => 'required|string|max:255',
'closed_hours_action' => 'required|string|max:255',
'schedule' => 'required|array',
'schedule.*.enabled' => 'boolean',
'schedule.*.time_ranges' => 'array',
'schedule.*.time_ranges.*.start_time' => 'required|date_format:H:i',
'schedule.*.time_ranges.*.end_time' => 'required|date_format:H:i|after:schedule.*.time_ranges.*.start_time',
'exceptions' => 'array',
'exceptions.*.date' => 'required|date|unique:business_hours_exceptions,date,NULL,id,business_hours_schedule_id,' . $scheduleId,
'exceptions.*.name' => 'required|string|max:255',
'exceptions.*.type' => 'required|in:closed,special_hours',
'exceptions.*.time_ranges' => 'required_if:exceptions.*.type,special_hours|array'
```

## 6. UI/UX Specifications

### 6.1 Main Business Hours Page

#### Wireframe - List View
```
┌─────────────────────────────────────────────────────────────┐
│ Business Hours Schedules                                  │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [+] Create Schedule                                     │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Standard Business Hours                    [Active] Open │ │
│ │ Monday-Friday: 9:00-12:00, 13:00-17:00     [•••]        │ │
│ │ Exceptions: Christmas Day (closed)          [Edit] [Del]│ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Summer Hours                               [Inactive]   │ │
│ │ Monday-Friday: 8:00-16:00                   [•••]        │ │
│ │ No exceptions                               [Edit] [Del]│ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

#### Wireframe - Empty State
```
┌─────────────────────────────────────────────────────────────┐
│ Business Hours Schedules                                  │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [+] Create Schedule                                     │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│                    [🕒]                                     │
│              No business hours schedules found             │
│                                                             │
│    Get started by creating your first business hours       │
│    schedule to enable time-based call routing.             │
│                                                             │
│              [Create Business Hours Schedule]              │
└─────────────────────────────────────────────────────────────┘
```

### 6.2 Schedule Creation/Edit Form

#### Wireframe - Basic Configuration
```
┌─────────────────────────────────────────────────────────────┐
│ Create Business Hours Schedule                            │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Schedule Name: [Standard Business Hours_____________]  │ │
│ │ Status: [Active ▼]                                      │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Open Hours Routing                                      │ │
│ │ Route calls during business hours to:                   │ │
│ │ [Extension ▼] [Select Extension_______________ ▼]       │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Closed Hours Routing                                    │ │
│ │ Route calls outside business hours to:                  │ │
│ │ [Voicemail ▼] [Main Voicemail_______________ ▼]         │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

#### Wireframe - Weekly Schedule Builder
```
┌─────────────────────────────────────────────────────────────┐
│ Weekly Schedule                                           │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Monday    [✓] [09:00] to [12:00] [+Add Range] [-]       │ │
│ │           [ ] [13:00] to [17:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Tuesday   [✓] [09:00] to [12:00] [+Add Range] [-]       │ │
│ │           [ ] [13:00] to [17:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Wednesday [✓] [09:00] to [12:00] [+Add Range] [-]       │ │
│ │           [ ] [13:00] to [17:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Thursday  [✓] [09:00] to [12:00] [+Add Range] [-]       │ │
│ │           [ ] [13:00] to [17:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Friday    [✓] [09:00] to [12:00] [+Add Range] [-]       │ │
│ │           [ ] [13:00] to [17:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Saturday  [ ] [09:00] to [12:00] [+Add Range]           │ │
│ │                                                         │ │
│ │ Sunday    [ ] [09:00] to [12:00] [+Add Range]           │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

#### Wireframe - Exceptions Management
```
┌─────────────────────────────────────────────────────────────┐
│ Exceptions                                                │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [+] Add Exception                                       │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Christmas Day (2024-12-25)                 [Closed] [×] │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ New Year's Eve (2024-12-31)              [Special] [×]  │ │
│ │ 20:00 to 23:59 [+Add Range]                           │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### 6.3 User Flows

#### Primary Flow - Creating a Schedule
1. User navigates to Business Hours page
2. Clicks "Create Schedule" button
3. Enters schedule name and selects status
4. Configures open hours routing (selects destination type and target)
5. Configures closed hours routing (selects destination type and target)
6. Builds weekly schedule by enabling days and adding time ranges
7. Optionally adds exceptions
8. Clicks "Save" to create the schedule
9. Returns to list view with success message

#### Secondary Flow - Editing a Schedule
1. User selects a schedule from the list
2. Clicks "Edit" button
3. Modifies any configuration as needed
4. Weekly schedule changes replace existing configuration
5. Exception changes replace existing configuration
6. Clicks "Update" to save changes
7. Returns to list view with success message

#### Error Flow - Validation Failure
1. User attempts to save invalid configuration
2. Form displays validation errors inline
3. User corrects errors and retries
4. On success, proceeds normally

## 7. Business Logic and Rules

### 7.1 Schedule Status Determination

#### Current Status Logic
```php
function getCurrentStatus(BusinessHoursSchedule $schedule): string {
    if ($schedule->status === 'inactive') {
        return 'closed';
    }

    $now = Carbon::now();
    $exception = $schedule->getExceptionForDate($now);

    if ($exception !== null) {
        return $exception->isOpen($now) ? 'open' : 'closed';
    }

    return $schedule->isCurrentlyOpen($now) ? 'open' : 'closed';
}
```

#### Weekly Schedule Logic
- For each day of week, check if enabled
- If enabled, check if current time falls within any time range
- Time ranges are inclusive of start time, exclusive of end time
- Multiple ranges per day are OR'd together

#### Exception Priority
1. Check for exception matching current date
2. If exception exists, use its rules instead of weekly schedule
3. Closed exceptions always return closed
4. Special hours exceptions use their custom time ranges

### 7.2 Routing Configuration

#### Action Format
Routing actions use format: `{type}:{target_id}`
- `extension:123` - Route to extension with ID 123
- `ring_group:456` - Route to ring group with ID 456
- `voicemail:main` - Route to voicemail

#### Validation Rules
- Destination must exist and be active
- Destination must belong to same organization
- Extension must be active and have valid configuration
- Ring group must be active and have members
- Voicemail must be configured

### 7.3 Time Range Validation

#### Overlap Prevention
- Within same day, time ranges cannot overlap
- Range A and B overlap if: A.start < B.end AND B.start < A.end
- Exception applies to both weekly schedules and special hours exceptions

#### Boundary Validation
- Start time must be before end time
- Times must be in valid HH:MM format
- Maximum 10 time ranges per day/exception

### 7.4 Exception Constraints
- Exception dates must be unique within schedule
- Special hours exceptions require at least one time range
- Closed exceptions ignore time ranges
- Future dates only (no past exceptions)

## 8. Edge Cases and Error Handling

### 8.1 Time Boundary Cases
**EC-BH-001**: Call at exactly 17:00 when schedule ends at 17:00
- Expected: Routes to closed hours (end time exclusive)

**EC-BH-002**: Call during overlap between two ranges
- Expected: Routes to open hours (any matching range)

**EC-BH-003**: Call on day with no ranges defined but marked enabled
- Expected: Routes to closed hours

### 8.2 Exception Cases
**EC-BH-004**: Multiple exceptions on same date (invalid)
- Validation: Prevent duplicate dates

**EC-BH-005**: Exception with no time ranges for special hours
- Validation: Require time ranges for special hours type

**EC-BH-006**: Exception date in past
- Validation: Allow but warn user

### 8.3 Configuration Errors
**EC-BH-007**: Routing destination deleted after schedule creation
- Runtime: Log error, route to busy signal

**EC-BH-008**: Schedule deleted while DID references it
- Cleanup: Prevent deletion if referenced, or update DIDs to fallback

**EC-BH-009**: Time zone issues (system assumes UTC)
- Mitigation: Document timezone assumptions clearly

### 8.4 System Failure Cases
**EC-BH-010**: Database unavailable during routing decision
- Fallback: Route to closed hours configuration

**EC-BH-011**: Cache invalidation fails
- Recovery: Use database directly, log performance impact

**EC-BH-012**: Concurrent schedule updates
- Prevention: Database transactions ensure consistency

### 8.5 Error Responses

#### Validation Errors (400)
```json
{
    "error": "Validation failed",
    "message": "The given data was invalid.",
    "errors": {
        "name": ["The name field is required."],
        "schedule.monday.time_ranges.0.end_time": ["End time must be after start time."]
    }
}
```

#### Authorization Errors (403)
```json
{
    "error": "Forbidden",
    "message": "You do not have permission to perform this action."
}
```

#### Not Found Errors (404)
```json
{
    "error": "Not Found",
    "message": "Business hours schedule not found."
}
```

#### Server Errors (500)
```json
{
    "error": "Internal Server Error",
    "message": "An error occurred while processing your request."
}
```

## 9. Testing Scenarios

### 9.1 Unit Tests

#### Schedule Status Logic
- Test open status during business hours
- Test closed status outside business hours
- Test exception overrides weekly schedule
- Test inactive schedule always returns closed

#### Time Range Validation
- Test valid time ranges (start < end)
- Test invalid time ranges (start >= end)
- Test overlapping ranges prevention
- Test multiple ranges per day

#### Exception Handling
- Test closed exception routing
- Test special hours exception routing
- Test exception date uniqueness
- Test exception time range validation

### 9.2 Integration Tests

#### API Endpoints
- Test CRUD operations with valid data
- Test validation with invalid data
- Test authorization and tenant isolation
- Test relationship loading and eager loading

#### Call Routing Integration
- Test routing during open hours
- Test routing during closed hours
- Test routing during exceptions
- Test routing when destination unavailable

### 9.3 End-to-End Tests

#### User Interface
- Test schedule creation workflow
- Test schedule editing workflow
- Test weekly schedule builder
- Test exception management
- Test form validation feedback

#### Call Flow Scenarios
- Simulate call at various times
- Verify correct routing destinations
- Test fallback behavior on errors
- Validate logging and audit trails

### 9.4 Performance Tests

#### Database Load
- Test query performance with many schedules
- Test relationship loading efficiency
- Test concurrent access patterns

#### Cache Performance
- Test cache hit ratios
- Test cache invalidation scenarios
- Test cache recovery from failures

### 9.5 Security Tests

#### Authorization
- Test cross-tenant access prevention
- Test role-based permissions
- Test API authentication requirements

#### Input Validation
- Test SQL injection prevention
- Test XSS prevention in names/descriptions
- Test buffer overflow protection

## 10. Integration Points

### 10.1 DID Number Management
**Integration**: Business hours schedules are selected as routing destinations for DID numbers
**Data Flow**: DID routing_type = 'business_hours', routing_config contains schedule ID
**Dependencies**: DID creation/editing forms must include business hours picker
**Impact**: Changes to schedules affect active call routing

### 10.2 Call Routing Service
**Integration**: Real-time call routing decisions use business hours status
**Data Flow**: CallRoutingService.routeByBusinessHours() evaluates current status and routes accordingly
**Dependencies**: Requires schedule data to be cached for performance
**Impact**: Routing failures could affect call quality

### 10.3 User/Extension Management
**Integration**: Routing destinations reference extensions and users
**Data Flow**: Schedule actions reference extension/ring group IDs
**Dependencies**: Extension deletion must validate no active references
**Impact**: Extension changes could break routing

### 10.4 Ring Group Management
**Integration**: Business hours can route to ring groups
**Data Flow**: Ring group IDs used in routing actions
**Dependencies**: Ring group status affects routing validity
**Impact**: Ring group changes affect call distribution

### 10.5 Voicemail System
**Integration**: Closed hours routing often goes to voicemail
**Data Flow**: Voicemail identifiers used in routing actions
**Dependencies**: Voicemail configuration must be validated
**Impact**: Voicemail unavailability affects closed hours handling

### 10.6 Audit Logging
**Integration**: All routing decisions are logged
**Data Flow**: Call logs include business hours evaluation results
**Dependencies**: Logging must include schedule IDs and decision rationale
**Impact**: Audit requirements drive logging verbosity

### 10.7 Real-time Updates
**Integration**: Frontend shows current schedule status
**Data Flow**: WebSocket updates when schedule status changes
**Dependencies**: Cache invalidation triggers status recalculation
**Impact**: Real-time accuracy affects user experience

### 10.8 Caching System
**Integration**: Schedule data cached for performance
**Data Flow**: Redis cache stores schedule configurations
**Dependencies**: Cache observers invalidate on changes
**Impact**: Cache failures could cause routing delays

This comprehensive specification provides the foundation for implementing and maintaining the Business Hours feature in the PBX application, ensuring it meets both functional and non-functional requirements.</content>
<parameter name="filePath">docs/BUSINESS_HOURS_SPECIFICATION.md