# Business Hours

## Overview
Time-based call routing with weekly schedules, exception dates (holidays/special hours), and timezone support. Routes to different destinations during open vs closed hours. Supports schedule duplication and holiday import.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/BusinessHoursController.php` | CRUD + duplicate + toggle (extends AbstractApiCrudController) |
| `app/Models/BusinessHoursSchedule.php` | Primary model (408 lines) |
| `app/Models/BusinessHoursScheduleDay.php` | Per-day schedule |
| `app/Models/BusinessHoursTimeRange.php` | Time intervals |
| `app/Models/BusinessHoursException.php` | Exception dates |
| `app/Models/BusinessHoursExceptionTimeRange.php` | Exception time intervals |
| `app/Models/BusinessHours.php` | Legacy model (same table, JSON-based) |
| `app/Enums/BusinessHoursStatus.php` | ACTIVE, INACTIVE |
| `app/Enums/BusinessHoursActionType.php` | EXTENSION, RING_GROUP, CONFERENCE_ROOM, IVR_MENU, AI_ASSISTANT, AI_LOAD_BALANCER |
| `app/Enums/BusinessHoursExceptionType.php` | CLOSED, SPECIAL_HOURS |
| `app/Enums/DayOfWeek.php` | MONDAY-SUNDAY with Carbon conversion |
| `app/Services/VoiceRouting/BusinessHoursRoutingService.php` | Real-time routing |
| `app/Http/Requests/BusinessHours/StoreBusinessHoursScheduleRequest.php` | Validation (340 lines) |
| `app/Policies/BusinessHoursSchedulePolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/BusinessHours.tsx` | Schedule management (2622 lines) |
| `frontend/src/services/businessHours.service.ts` | API calls + duplicate |
| `frontend/src/utils/businessHours.ts` | Schedule summary generation |

## Database Tables

### `business_hours_schedules`
| Column | Type | Notes |
|--------|------|-------|
| id, organization_id | FK | Tenant scope, soft deletes |
| name | string | Unique per org |
| status | enum | active, inactive |
| timezone | string | IANA timezone |
| open_hours_action | JSON | `{target_id: "ext-13"}` |
| open_hours_action_type | enum | BusinessHoursActionType |
| closed_hours_action | JSON | `{target_id: "rg-5"}` |
| closed_hours_action_type | enum | BusinessHoursActionType |

### `business_hours_schedule_days` (7 per schedule)
`id, business_hours_schedule_id, day_of_week (enum), enabled (bool)`

### `business_hours_time_ranges` (per day)
`id, business_hours_schedule_day_id, start_time, end_time`

### `business_hours_exceptions`
`id, business_hours_schedule_id, date (Y-m-d), name, type (closed/special_hours)`

### `business_hours_exception_time_ranges` (per exception)
`id, business_hours_exception_id, start_time, end_time`

## Target ID Convention
Prefixed IDs used in `open_hours_action` and `closed_hours_action`:
- `ext-{id}` -> Extension
- `rg-{id}` -> Ring Group
- `conf-{id}` -> Conference Room
- `ivr-{id}` -> IVR Menu
- `ai-{id}` -> AI Assistant
- `alb-{id}` -> AI Load Balancer

Parsed by `BusinessHoursSchedule::parseTargetId()` (line 240)

## Open/Closed Logic (BusinessHoursSchedule::isCurrentlyOpen, line 161)
1. Check exceptions by date (`getExceptionForDate()`)
2. If CLOSED exception: always closed
3. If SPECIAL_HOURS exception: check exception time ranges
4. If no exception: check day-of-week schedule via `DayOfWeek::fromCarbonDayOfWeek()`
5. If day disabled or not found: closed
6. Check time ranges: current time >= start_time AND < end_time

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/business-hours[/{businessHour}]` | apiResource |
| POST | `/v1/business-hours/{businessHour}/duplicate` | Deep copy with "(Copy)" suffix |
| PATCH | `/v1/business-hours/{businessHour}/toggle-status` | Status toggle |

## Frontend-Backend Data Transformation
- Frontend sends: keyed object `{monday: {enabled, time_ranges}, tuesday: {...}}` + actions
- Controller `prepareBusinessHoursData()`: transforms to backend arrays
- Resource reconstructs keyed format for API responses
- Delete-and-recreate pattern for schedule days and exceptions on update

## Schedule Templates (Frontend)
`mon-fri-business`, `mon-fri-all-day`, `sun-thu-business`, `sun-thu-all-day`, `24-7`

## Holiday Import (Frontend)
Fetches public holidays from `date.nager.at` API by country/year. User selects holidays to import as CLOSED exceptions.

## Related Modules
- [Voice Routing](voice-routing-engine.md) - BusinessHoursRoutingService for live routing
- [Phone Numbers](phone-numbers.md) - DIDs can route to business hours
- [Destination Routing](destination-routing-system.md) - Actions use destination type system
