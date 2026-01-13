# Business Hours Functional Specification

## Overview
The business hours feature enables organizations to configure time-based routing for inbound calls. During configured business hours, calls can be routed to specific destinations (extensions, ring groups, etc.), while outside business hours, calls can be routed to alternative destinations such as voicemail, automated attendants, or emergency contacts.

## Requirements

### Core Functionality

#### 1. Business Hours Configuration
- **Time Zones**: Support for configuring business hours in the organization's local time zone
- **Weekly Schedule**: Ability to configure different hours for each day of the week
- **Multiple Time Ranges**: Support for multiple time ranges per day (e.g., morning 9-12, afternoon 13-17)
- **Holidays**: Support for defining holiday dates where normal business hours don't apply
- **Exceptions**: Ability to override default business hours for specific dates

#### 2. Routing Behavior

##### During Business Hours
- **Primary Routing**: Calls route to configured business hours destination
- **Fallback Options**: If primary destination is unavailable, route to secondary options

##### Outside Business Hours
- **After Hours Routing**: Calls route to configured after-hours destination
- **Voicemail Integration**: Option to route to voicemail with custom greeting
- **Automated Attendant**: Option to route to IVR/auto-attendant
- **Emergency Contact**: Option to route to designated emergency contact

#### 3. Integration Points

##### DID Routing
- Business hours routing can be selected as a routing option for DIDs
- Each DID can have its own business hours configuration or inherit from organization defaults

##### Ring Groups
- Business hours routing can be combined with ring group strategies
- Different ring groups can be used for business vs after-hours routing

### User Interface Requirements

#### Admin Configuration
- **Schedule Builder**: Visual interface for configuring weekly business hours
- **Time Picker**: User-friendly time selection with AM/PM support
- **Day Templates**: Ability to copy hours from one day to another
- **Validation**: Ensure time ranges don't overlap and are logically valid

#### Holiday Management
- **Holiday Calendar**: Interface for managing holiday dates
- **Recurring Holidays**: Support for annual recurring holidays
- **Bulk Operations**: Ability to add multiple holidays at once

### Data Model

#### BusinessHours Entity
```sql
business_hours:
- id (primary key)
- organization_id (foreign key)
- name (string)
- description (text, optional)
- timezone (string)
- is_default (boolean)
- created_at, updated_at
```

#### BusinessHoursSchedule Entity
```sql
business_hours_schedules:
- id (primary key)
- business_hours_id (foreign key)
- day_of_week (integer, 0-6, 0=Sunday)
- start_time (time)
- end_time (time)
- is_active (boolean)
- created_at, updated_at
```

#### BusinessHoursHolidays Entity
```sql
business_hours_holidays:
- id (primary key)
- business_hours_id (foreign key)
- name (string)
- date (date)
- is_recurring (boolean)
- created_at, updated_at
```

#### BusinessHoursOverrides Entity
```sql
business_hours_overrides:
- id (primary key)
- business_hours_id (foreign key)
- override_date (date)
- start_time (time, optional)
- end_time (time, optional)
- is_closed (boolean)
- created_at, updated_at
```

### API Endpoints

#### Configuration Endpoints
- `GET /api/organizations/{orgId}/business-hours` - List business hours configurations
- `POST /api/organizations/{orgId}/business-hours` - Create new business hours configuration
- `GET /api/business-hours/{id}` - Get specific business hours configuration
- `PUT /api/business-hours/{id}` - Update business hours configuration
- `DELETE /api/business-hours/{id}` - Delete business hours configuration

#### Schedule Management
- `GET /api/business-hours/{id}/schedule` - Get weekly schedule
- `PUT /api/business-hours/{id}/schedule` - Update weekly schedule
- `POST /api/business-hours/{id}/holidays` - Add holiday
- `DELETE /api/business-hours/{id}/holidays/{holidayId}` - Remove holiday

#### Runtime Endpoints
- `GET /api/business-hours/{id}/status` - Check if currently within business hours
- `POST /api/business-hours/evaluate` - Evaluate routing for current time

### Webhook Integration

#### Call Routing Logic
When a call arrives at a DID configured with business hours routing:

1. **Time Check**: Determine if current time is within business hours
2. **Holiday Check**: Verify if current date is a holiday
3. **Override Check**: Apply any date-specific overrides
4. **Route Decision**: Route to appropriate destination based on time status

#### CXML Generation
```xml
<!-- During business hours -->
<Connect>
  <Dial>
    <Number>extension-number</Number>
  </Dial>
</Connect>

<!-- Outside business hours -->
<Connect>
  <Dial>
    <Number>after-hours-number</Number>
  </Dial>
</Connect>
```

### Security Considerations

#### Multi-Tenant Isolation
- All business hours configurations are scoped to organization
- Users can only access business hours for their organization

#### RBAC Permissions
- **Owner/Admin**: Full CRUD access to business hours configurations
- **Agent**: Read-only access, can view current status

### Performance Requirements

#### Caching Strategy
- Business hours status should be cached in Redis for fast lookup
- Cache invalidation on configuration changes
- TTL-based expiration with automatic refresh

#### Database Optimization
- Efficient queries for time-based lookups
- Indexed columns for day_of_week, date fields
- Minimal database hits during call processing

### Testing Requirements

#### Unit Tests
- Business hours evaluation logic
- Time zone conversions
- Holiday date calculations
- Override application logic

#### Integration Tests
- Full call routing flow during business hours
- Full call routing flow outside business hours
- Holiday routing behavior
- Override behavior

#### Edge Cases
- Time zone boundary crossings
- Daylight saving time transitions
- Leap year calculations
- Multiple overlapping time ranges

### Migration Path

#### Existing Systems
For organizations upgrading from basic routing:
1. Create default business hours configuration (9-5, Mon-Fri)
2. Set existing routing as "business hours" destination
3. Configure voicemail as "after hours" destination

#### Backward Compatibility
- Existing DIDs without business hours continue to work
- Business hours is opt-in feature
- No breaking changes to existing routing logic