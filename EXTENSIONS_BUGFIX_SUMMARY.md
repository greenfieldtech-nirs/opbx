# Extensions Dialog Critical Bugfixes - Implementation Summary

## Date: 2025-12-27
## File Modified: `/frontend/src/pages/Extensions.tsx`

## Problems Identified and Fixed

### Issue 1: user_id Always Being Sent (CRITICAL)
**Problem:** The frontend was sending `user_id` field in ALL extension creation/update requests, including non-user extension types (conference, ring_group, IVR, etc.).

**Backend Validation Rule (UpdateExtensionRequest.php:225-230):**
```php
// Non-USER type extensions should not have a user_id
if ($type !== ExtensionType::USER->value && $this->has('user_id') && $userId) {
    $validator->errors()->add('user_id', 'User ID should only be set for user extensions.');
}
```

**Error Message Received:**
```
"User ID should only be set for user extensions."
```

**Fix Applied:**
- Modified `handleCreateExtension()` (lines 554-569)
- Modified `handleEditExtension()` (lines 631-645)
- Changed from always including `user_id` to conditionally including it ONLY for 'user' type extensions
- When type is NOT 'user', the field is completely omitted from the request payload

**Before:**
```typescript
const createData: CreateExtensionRequest = {
  extension_number: formData.extension_number,
  type: formData.type,
  status: formData.status,
  user_id: formData.user_id === 'unassigned' || !formData.user_id ? null : parseInt(formData.user_id, 10),
  voicemail_enabled: false,
  configuration,
};
```

**After:**
```typescript
const createData: CreateExtensionRequest = {
  extension_number: formData.extension_number,
  type: formData.type,
  status: formData.status,
  voicemail_enabled: false,
  configuration,
};

// Only include user_id for USER type extensions
if (formData.type === 'user') {
  if (formData.user_id && formData.user_id !== 'unassigned') {
    createData.user_id = parseInt(formData.user_id, 10);
  } else {
    createData.user_id = null;
  }
}
```

### Issue 2: Configuration Fields Sending null/NaN Instead of Integers (CRITICAL)
**Problem:** When parsing empty or invalid configuration fields (conference_room_id, ring_group_id, ivr_id), `parseInt('', 10)` returns `NaN`, which gets serialized as `null` in JSON, causing backend validation failures.

**Backend Validation Rule (UpdateExtensionRequest.php:90-94):**
```php
'configuration.conference_room_id' => [
    Rule::requiredIf(fn() => $this->input('type') === ExtensionType::CONFERENCE->value),
    'nullable',
    'integer',  // Must be an integer, not null!
],
```

**Error Message Received:**
```
"Conference room ID is required for conference extensions."
```

**Fix Applied:**
- Modified configuration building in both `handleCreateExtension()` and `handleEditExtension()`
- Added proper validation before parsing integers
- Only set configuration field if value exists AND parses to valid integer

**Before:**
```typescript
case 'conference':
  configuration.conference_room_id = parseInt(formData.conference_room_id, 10);
  break;
```

**After:**
```typescript
case 'conference':
  if (formData.conference_room_id) {
    const parsed = parseInt(formData.conference_room_id, 10);
    if (!isNaN(parsed)) {
      configuration.conference_room_id = parsed;
    }
  }
  break;
```

### Issue 3: Using Mock Data Instead of Real API Data
**Problem:** Conference rooms and ring groups were using hardcoded mock data instead of fetching from the API.

**Fix Applied:**
- Added React Query hooks to fetch conference rooms from `/api/v1/conference-rooms` (lines 225-232)
- Added React Query hooks to fetch ring groups from `/api/v1/ring-groups` (lines 234-241)
- Added service imports: `conferenceRoomsService`, `ringGroupsService` (lines 17-18)
- Queries are enabled conditionally based on selected extension type (performance optimization)
- Replaced mock data in Select components with real API data (lines 746-750, 776-780)

**Before:**
```typescript
const mockConferenceRooms = [
  { id: 'conf-1', name: 'Main Conference Room', number: '8000' },
  // ...
];

<SelectContent>
  {mockConferenceRooms.map((room) => (
    <SelectItem key={room.id} value={room.id}>
      {room.name} ({room.number})
    </SelectItem>
  ))}
</SelectContent>
```

**After:**
```typescript
const { data: conferenceRoomsData } = useQuery({
  queryKey: ['conference-rooms', { per_page: 100, status: 'active' }],
  queryFn: () => conferenceRoomsService.getAll({ per_page: 100, status: 'active' }),
  enabled: formData.type === 'conference',
});

const conferenceRooms = conferenceRoomsData?.data || [];

<SelectContent>
  {conferenceRooms.map((room) => (
    <SelectItem key={room.id} value={room.id.toString()}>
      {room.name}
    </SelectItem>
  ))}
</SelectContent>
```

### Issue 4: Poor Error Handling in Mutations
**Problem:** Mutation error handlers were not showing Laravel validation errors properly.

**Fix Applied:**
- Enhanced error handling in `createMutation` (lines 252-261)
- Enhanced error handling in `updateMutation` (lines 273-282)
- Now properly extracts and displays Laravel validation errors from `errors` object
- Falls back to generic error message if no validation errors present

**Before:**
```typescript
onError: (error: any) => {
  const message = error.response?.data?.error?.message || 'Failed to create extension';
  toast.error(message);
}
```

**After:**
```typescript
onError: (error: any) => {
  const errors = error.response?.data?.errors;
  if (errors) {
    // Show first validation error
    const firstError = Object.values(errors)[0];
    toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
  } else {
    const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to create extension';
    toast.error(message);
  }
}
```

## Expected Request Format After Fixes

### For Conference Extension (non-user type):
```json
{
  "extension_number": "1001",
  "type": "conference",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "conference_room_id": 1
  }
}
```
**Note:** NO `user_id` field at all!

### For Ring Group Extension (non-user type):
```json
{
  "extension_number": "1002",
  "type": "ring_group",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "ring_group_id": 2
  }
}
```
**Note:** NO `user_id` field at all!

### For User Extension:
```json
{
  "extension_number": "1003",
  "type": "user",
  "status": "active",
  "user_id": 5,
  "voicemail_enabled": false,
  "configuration": {}
}
```
**Note:** `user_id` field IS included for user type!

## Test Cases to Verify

1. **Create conference extension** with valid conference room ID → Should succeed with NO user_id in request
2. **Create ring group extension** with valid ring group ID → Should succeed with NO user_id in request
3. **Create IVR extension** with valid IVR menu ID → Should succeed with NO user_id in request
4. **Create user extension** with assigned user → Should succeed with user_id in request
5. **Create user extension** unassigned → Should succeed with user_id=null in request
6. **Edit conference extension** → Should update without sending user_id
7. **Validation errors** from backend → Should display clear error message to user
8. **Empty configuration fields** → Should not send NaN or null, should omit field entirely

## Files Changed

1. `/frontend/src/pages/Extensions.tsx` - Main implementation file with all fixes

## Dependencies Added

- Import: `conferenceRoomsService` from `@/services/conferenceRooms.service`
- Import: `ringGroupsService` from `@/services/ringGroups.service`

## Breaking Changes

None. This is a bug fix that makes the frontend conform to existing backend validation rules.

## Performance Improvements

- Conference rooms and ring groups are now fetched only when their respective extension type is selected (`enabled: formData.type === 'conference'`)
- Reduces unnecessary API calls when creating other extension types

## Code Quality Improvements

- More robust integer parsing with NaN checks
- Better error message display from Laravel validation
- Proper conditional field inclusion based on extension type
- Real-time data from API instead of stale mock data

## Security Considerations

- Proper validation before parsing integers prevents type coercion issues
- No user_id sent for non-user extensions prevents potential privilege escalation
- Backend validation remains the source of truth

## Next Steps

1. Test all extension types creation in UI
2. Test all extension types editing in UI
3. Verify validation errors display correctly
4. Monitor for any edge cases with empty or invalid data
5. Consider adding frontend validation to prevent submitting invalid forms

## Notes

- IVR menu data is still using mock data (no API endpoint exists yet)
- Voicemail is currently disabled per project requirements
- All changes maintain backward compatibility with existing extension data
