# Extensions.tsx - Critical Bug Fixes - Code Changes

## 1. Added Service Imports (Lines 15-18)

```typescript
// ADDED:
import { conferenceRoomsService } from '@/services/conferenceRooms.service';
import { ringGroupsService } from '@/services/ringGroups.service';
```

## 2. Added Real API Data Fetching (Lines 225-241)

```typescript
// ADDED: Fetch conference rooms from API
const { data: conferenceRoomsData } = useQuery({
  queryKey: ['conference-rooms', { per_page: 100, status: 'active' }],
  queryFn: () => conferenceRoomsService.getAll({ per_page: 100, status: 'active' }),
  enabled: formData.type === 'conference', // Only fetch when needed
});

const conferenceRooms = conferenceRoomsData?.data || [];

// ADDED: Fetch ring groups from API
const { data: ringGroupsData } = useQuery({
  queryKey: ['ring-groups', { per_page: 100, status: 'active' }],
  queryFn: () => ringGroupsService.getAll({ per_page: 100, status: 'active' }),
  enabled: formData.type === 'ring_group', // Only fetch when needed
});

const ringGroups = ringGroupsData?.data || [];
```

## 3. Enhanced Error Handling in Mutations (Lines 252-261, 273-282)

### Create Mutation Error Handler:
```typescript
// CHANGED: Better validation error handling
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

### Update Mutation Error Handler:
```typescript
// CHANGED: Better validation error handling
onError: (error: any) => {
  const errors = error.response?.data?.errors;
  if (errors) {
    // Show first validation error
    const firstError = Object.values(errors)[0];
    toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
  } else {
    const message = error.response?.data?.message || error.response?.data?.error?.message || 'Failed to update extension';
    toast.error(message);
  }
}
```

## 4. Fixed Configuration Parsing (Lines 517-552 in handleCreateExtension)

### Before:
```typescript
case 'conference':
  configuration.conference_room_id = parseInt(formData.conference_room_id, 10);
  break;
case 'ring_group':
  configuration.ring_group_id = parseInt(formData.ring_group_id, 10);
  break;
case 'ivr':
  configuration.ivr_id = parseInt(formData.ivr_id, 10);
  break;
```

### After:
```typescript
case 'conference':
  if (formData.conference_room_id) {
    const parsed = parseInt(formData.conference_room_id, 10);
    if (!isNaN(parsed)) {
      configuration.conference_room_id = parsed;
    }
  }
  break;
case 'ring_group':
  if (formData.ring_group_id) {
    const parsed = parseInt(formData.ring_group_id, 10);
    if (!isNaN(parsed)) {
      configuration.ring_group_id = parsed;
    }
  }
  break;
case 'ivr':
  if (formData.ivr_id) {
    const parsed = parseInt(formData.ivr_id, 10);
    if (!isNaN(parsed)) {
      configuration.ivr_id = parsed;
    }
  }
  break;
```

## 5. Conditional user_id Field (Lines 554-569 in handleCreateExtension)

### Before:
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

### After:
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

## 6. Same Changes Applied to handleEditExtension (Lines 594-645)

Configuration parsing and conditional user_id logic duplicated in edit handler.

## 7. Updated Select Components to Use Real Data

### Conference Rooms (Lines 746-750):
```typescript
// BEFORE:
<SelectContent>
  {mockConferenceRooms.map((room) => (
    <SelectItem key={room.id} value={room.id}>
      {room.name} ({room.number})
    </SelectItem>
  ))}
</SelectContent>

// AFTER:
<SelectContent>
  {conferenceRooms.map((room) => (
    <SelectItem key={room.id} value={room.id.toString()}>
      {room.name}
    </SelectItem>
  ))}
</SelectContent>
```

### Ring Groups (Lines 776-780):
```typescript
// BEFORE:
<SelectContent>
  {mockRingGroups.map((group) => (
    <SelectItem key={group.id} value={group.id}>
      {group.name}
    </SelectItem>
  ))}
</SelectContent>

// AFTER:
<SelectContent>
  {ringGroups.map((group) => (
    <SelectItem key={group.id} value={group.id.toString()}>
      {group.name}
    </SelectItem>
  ))}
</SelectContent>
```

## Summary of Changes

| Line Range | Change Type | Description |
|------------|-------------|-------------|
| 17-18 | Addition | Import conference rooms and ring groups services |
| 225-241 | Addition | Add React Query hooks to fetch real data |
| 252-261 | Modification | Enhanced create mutation error handling |
| 273-282 | Modification | Enhanced update mutation error handling |
| 517-552 | Modification | Fixed configuration integer parsing with NaN checks |
| 554-569 | Modification | Conditional user_id field inclusion (create) |
| 594-645 | Modification | Same configuration and user_id fixes (edit) |
| 746-750 | Modification | Use real conference room data |
| 776-780 | Modification | Use real ring group data |

## Testing Checklist

- [ ] Create conference extension (should NOT send user_id)
- [ ] Create ring group extension (should NOT send user_id)
- [ ] Create user extension with user (should send user_id)
- [ ] Create user extension unassigned (should send user_id=null)
- [ ] Edit conference extension (should NOT send user_id)
- [ ] Edit user extension (should send user_id)
- [ ] Verify validation errors display properly
- [ ] Verify conference rooms load from API
- [ ] Verify ring groups load from API
- [ ] Test with empty/invalid configuration values
