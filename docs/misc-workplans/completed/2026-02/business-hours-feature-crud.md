# Business Hours Feature CRUD Implementation Specification

## Overview

This specification details the implementation of the Business Hours feature CRUD operations, focusing on database storage and API endpoints. The UI/UX is already implemented and will be hooked to the updated API endpoints.

## 1. Current UI/UX Implementation (Reference Only)

The UI is already implemented with:
- List view with pagination, search, filters
- Create/Edit dialog with calendar-style weekly schedule
- Action selectors supporting Extension, Ring Group, IVR Menu
- Schedule templates and exception management
- All UI components handle structured data format

## 2. Database Schema Updates

### 2.1 Required Changes

#### business_hours_schedules table - Updated
```sql
-- Existing columns
- id (BIGINT, PRIMARY KEY)
- organization_id (BIGINT, FOREIGN KEY)
- name (VARCHAR(255))
- status (ENUM: active, inactive)
- open_hours_action (VARCHAR(255)) -- Will be updated to store JSON
- closed_hours_action (VARCHAR(255)) -- Will be updated to store JSON

-- New columns to add
- open_hours_action_type (ENUM: extension, ring_group, ivr_menu)
- closed_hours_action_type (ENUM: extension, ring_group, ivr_menu)

-- Existing indexes
- INDEXES: organization_id + status, deleted_at
```

### 2.2 Data Storage Format

#### JSON Structure in action fields:
```json
{
  "type": "extension|ring_group|ivr_menu",
  "target_id": "string_id"
}
```

#### Migration Strategy:
1. Add new ENUM columns
2. Update existing data to JSON format
3. Add database constraints

## 3. API Endpoints (Updates Required)

### 3.1 Current Endpoints (from BusinessHoursController)
```
GET    /api/v1/business-hours              # List schedules (paginated)
POST   /api/v1/business-hours              # Create schedule
GET    /api/v1/business-hours/{id}         # Get schedule details
PUT    /api/v1/business-hours/{id}         # Update schedule
DELETE /api/v1/business-hours/{id}         # Soft delete schedule
POST   /api/v1/business-hours/{id}/duplicate # Duplicate schedule
```

### 3.2 Required Updates

#### Request Format Changes:
```json
// Current: Simple string IDs
{
  "open_hours_action": "ext-101",
  "closed_hours_action": "ext-voicemail"
}

// New: Structured objects
{
  "open_hours_action": {
    "type": "extension",
    "target_id": "ext-101"
  },
  "closed_hours_action": {
    "type": "ivr_menu", 
    "target_id": "ivr-10"
  }
}
```

#### Response Format (Already matches UI expectations):
```json
{
  "id": 1,
  "name": "Main Office Hours",
  "status": "active",
  "open_hours_action": {
    "type": "extension",
    "target_id": "ext-101"
  },
  "closed_hours_action": {
    "type": "ivr_menu",
    "target_id": "ivr-10"
  },
  "schedule_days": [...],
  "exceptions": [...]
}
```

## 4. Backend Implementation Plan

### 4.1 Database Migration
**File**: `database/migrations/2025_12_27_202223_update_business_hours_actions.php`

```php
public function up(): void
{
    Schema::table('business_hours_schedules', function (Blueprint $table) {
        // Add new ENUM columns
        $table->enum('open_hours_action_type', ['extension', 'ring_group', 'ivr_menu'])->nullable();
        $table->enum('closed_hours_action_type', ['extension', 'ring_group', 'ivr_menu'])->nullable();
    });

    // Migrate existing data
    DB::table('business_hours_schedules')->get()->each(function ($schedule) {
        // Transform existing string IDs to JSON format
        $openAction = json_encode(['type' => 'extension', 'target_id' => $schedule->open_hours_action]);
        $closedAction = json_encode(['type' => 'extension', 'target_id' => $schedule->closed_hours_action]);

        DB::table('business_hours_schedules')
            ->where('id', $schedule->id)
            ->update([
                'open_hours_action' => $openAction,
                'open_hours_action_type' => 'extension',
                'closed_hours_action' => $closedAction,
                'closed_hours_action_type' => 'extension',
            ]);
    });
}
```

### 4.2 Model Updates

#### BusinessHoursSchedule Model
**File**: `app/Models/BusinessHoursSchedule.php`

```php
// Add to fillable
protected $fillable = [
    // ... existing fields
    'open_hours_action_type',
    'closed_hours_action_type',
];

// Update casts
protected function casts(): array
{
    return [
        'status' => BusinessHoursStatus::class,
        'open_hours_action' => 'array', // Auto-decode JSON
        'closed_hours_action' => 'array', // Auto-decode JSON
        'open_hours_action_type' => BusinessHoursActionType::class,
        'closed_hours_action_type' => BusinessHoursActionType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
```

#### New Enum: BusinessHoursActionType
**File**: `app/Enums/BusinessHoursActionType.php`

```php
enum BusinessHoursActionType: string
{
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case IVR_MENU = 'ivr_menu';

    public static function values(): array
    {
        return array_map(fn(self $type) => $type->value, self::cases());
    }
}
```

### 4.3 Request Validation Updates

#### StoreBusinessHoursScheduleRequest
**File**: `app/Http/Requests/BusinessHours/StoreBusinessHoursScheduleRequest.php`

```php
public function rules(): array
{
    return [
        // ... existing rules ...

        'open_hours_action' => [
            'required',
            'string',
            function ($attribute, $value, $fail) {
                $data = json_decode($value, true);
                if (!$data || !isset($data['type']) || !isset($data['target_id'])) {
                    $fail('Invalid action format');
                }
                if (!in_array($data['type'], BusinessHoursActionType::values())) {
                    $fail('Invalid action type');
                }
            }
        ],
        'open_hours_action_type' => [
            'required',
            new Enum(BusinessHoursActionType::class),
        ],

        'closed_hours_action' => [
            'required',
            'string',
            function ($attribute, $value, $fail) {
                $data = json_decode($value, true);
                if (!$data || !isset($data['type']) || !isset($data['target_id'])) {
                    $fail('Invalid action format');
                }
                if (!in_array($data['type'], BusinessHoursActionType::values())) {
                    $fail('Invalid action type');
                }
            }
        ],
        'closed_hours_action_type' => [
            'required',
            new Enum(BusinessHoursActionType::class),
        ],

        // ... rest of rules ...
    ];
}
```

### 4.4 Controller Updates

#### BusinessHoursController
**File**: `app/Http/Controllers/Api/BusinessHoursController.php`

Add data transformation methods:

```php
/**
 * Transform action data from structured format to storage format
 */
protected function transformActionDataForStorage(array $data): array
{
    return [
        'open_hours_action' => json_encode([
            'type' => $data['open_hours_action']['type'],
            'target_id' => $data['open_hours_action']['target_id']
        ]),
        'open_hours_action_type' => $data['open_hours_action']['type'],
        'closed_hours_action' => json_encode([
            'type' => $data['closed_hours_action']['type'],
            'target_id' => $data['closed_hours_action']['target_id']
        ]),
        'closed_hours_action_type' => $data['closed_hours_action']['type'],
    ];
}

/**
 * Transform schedule for API response (JSON decode actions)
 */
protected function transformScheduleForResponse(BusinessHoursSchedule $schedule): array
{
    $data = $schedule->toArray();

    // Auto-decoded by model casts, but ensure consistency
    $data['open_hours_action'] = $schedule->open_hours_action;
    $data['closed_hours_action'] = $schedule->closed_hours_action;

    return $data;
}
```

Update store and update methods to use transformation:

```php
public function store(StoreBusinessHoursScheduleRequest $request): JsonResponse
{
    $validated = $request->validated();

    // Transform actions to storage format
    $actionData = $this->transformActionDataForStorage($validated);

    $schedule = DB::transaction(function () use ($user, $validated, $actionData): BusinessHoursSchedule {
        $schedule = BusinessHoursSchedule::create([
            'organization_id' => $user->organization_id,
            'name' => $validated['name'],
            'status' => $validated['status'],
            ...$actionData, // Include transformed action data
        ]);

        // ... rest of creation logic ...
    });

    return response()->json([
        'message' => 'Business hours schedule created successfully.',
        'data' => $this->transformScheduleForResponse($schedule),
    ], 201);
}
```

### 4.5 API Resource Updates

#### BusinessHoursScheduleResource
**File**: `app/Http/Resources/BusinessHoursScheduleResource.php`

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'organization_id' => $this->organization_id,
        'name' => $this->name,
        'status' => $this->status,
        'open_hours_action' => $this->open_hours_action, // Auto-decoded JSON
        'closed_hours_action' => $this->closed_hours_action, // Auto-decoded JSON
        'schedule_days' => BusinessHoursScheduleDayResource::collection($this->whenLoaded('scheduleDays')),
        'exceptions' => BusinessHoursExceptionResource::collection($this->whenLoaded('exceptions')),
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}
```

## 5. Frontend Service Updates

### 5.1 Business Hours Service
**File**: `frontend/src/services/businessHours.service.ts`

The service should already work with the new API format since it expects structured objects.

## 6. Implementation Order

1. **Database Migration** - Add new columns and migrate data
2. **New Enum** - BusinessHoursActionType
3. **Model Updates** - Add casts and fillable fields
4. **Request Validation** - Update validation rules
5. **Controller Updates** - Add data transformation methods
6. **API Resources** - Update response formatting
7. **Testing** - Verify all CRUD operations work
8. **Regression Testing** - Ensure no breaking changes

## 7. Testing Requirements

### 7.1 Unit Tests
- Model casting works correctly
- Data transformation methods
- Validation rules
- Enum functionality

### 7.2 Feature Tests
- Create schedule with all action types
- Update schedule actions
- Read schedule with proper JSON decoding
- Delete schedule
- Duplicate schedule preserves action data

### 7.3 API Integration Tests
- Request validation accepts new format
- Response format matches expectations
- Error handling for invalid action types
- Backward compatibility checks

## 8. Migration Safety

### 8.1 Rollback Plan
- Migration rollback removes new columns
- Data restoration from backups if needed
- Feature flag to disable new functionality

### 8.2 Data Integrity
- Validate all existing schedules can be migrated
- Ensure JSON structure is valid
- Test enum constraints

This specification focuses exclusively on the CRUD API implementation, ensuring the existing UI/UX can seamlessly integrate with the updated backend that supports structured action routing.</content>
<parameter name="filePath">docs/workplans/business-hours-feature-crud.md