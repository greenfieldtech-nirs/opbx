# Extensions Dialog - Test Scenarios

## Test Environment Setup

1. Ensure backend is running
2. Ensure at least one conference room exists in database
3. Ensure at least one ring group exists in database
4. Ensure at least one user exists for assignment
5. Open browser dev tools Network tab to inspect requests

## Test Scenario 1: Create Conference Extension

### Steps:
1. Navigate to Extensions page
2. Click "Add Extension" button
3. Fill in form:
   - Extension Number: `8001`
   - Type: Select "Conference Room"
   - Status: `active`
   - Conference Room: Select any available room
4. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "8001",
  "type": "conference",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "conference_room_id": 1
  }
}
```

### Assertions:
- ✅ Request should NOT include `user_id` field at all
- ✅ `configuration.conference_room_id` should be an integer, not null or NaN
- ✅ Request should succeed with 201 Created
- ✅ Success toast message displayed
- ✅ Dialog closes automatically
- ✅ Extension appears in the list

### Common Errors (Should NOT occur):
- ❌ "User ID should only be set for user extensions"
- ❌ "Conference room ID is required for conference extensions"

---

## Test Scenario 2: Create Ring Group Extension

### Steps:
1. Click "Add Extension" button
2. Fill in form:
   - Extension Number: `7001`
   - Type: Select "Ring Group"
   - Status: `active`
   - Ring Group: Select any available group
3. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "7001",
  "type": "ring_group",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "ring_group_id": 2
  }
}
```

### Assertions:
- ✅ Request should NOT include `user_id` field
- ✅ `configuration.ring_group_id` should be an integer
- ✅ Request should succeed
- ✅ Extension appears in the list

---

## Test Scenario 3: Create User Extension (Assigned)

### Steps:
1. Click "Add Extension" button
2. Fill in form:
   - Extension Number: `1001`
   - Assign to User: Select a user from dropdown
   - Type: "PBX User Extension" (default)
   - Status: `active`
3. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "1001",
  "type": "user",
  "status": "active",
  "user_id": 5,
  "voicemail_enabled": false,
  "configuration": {}
}
```

### Assertions:
- ✅ Request SHOULD include `user_id` field with integer value
- ✅ `user_id` should match selected user's ID
- ✅ Request should succeed
- ✅ Extension shows user name in table

---

## Test Scenario 4: Create User Extension (Unassigned)

### Steps:
1. Click "Add Extension" button
2. Fill in form:
   - Extension Number: `1002`
   - Assign to User: Select "Leave Unassigned"
   - Type: "PBX User Extension"
   - Status: `active`
3. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "1002",
  "type": "user",
  "status": "active",
  "user_id": null,
  "voicemail_enabled": false,
  "configuration": {}
}
```

### Assertions:
- ✅ Request SHOULD include `user_id` field with null value
- ✅ Request should succeed
- ✅ Extension shows "Unassigned" in table

---

## Test Scenario 5: Create AI Assistant Extension

### Steps:
1. Click "Add Extension" button
2. Fill in form:
   - Extension Number: `9001`
   - Type: Select "AI Assistant"
   - AI Provider: Select "VAPI"
   - Phone Number: `+1234567890`
   - Status: `active`
3. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "9001",
  "type": "ai_assistant",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "provider": "VAPI",
    "phone_number": "+1234567890"
  }
}
```

### Assertions:
- ✅ Request should NOT include `user_id` field
- ✅ Configuration should include provider and phone_number as strings
- ✅ Request should succeed

---

## Test Scenario 6: Edit Conference Extension

### Steps:
1. Find a conference extension in the list
2. Click the three-dot menu and select "Edit Extension"
3. Change status to `inactive`
4. Click "Save Changes"

### Expected Request Payload:
```json
{
  "type": "conference",
  "status": "inactive",
  "voicemail_enabled": false,
  "configuration": {
    "conference_room_id": 1
  }
}
```

### Assertions:
- ✅ Request should NOT include `user_id` field
- ✅ Only changed fields are sent
- ✅ Request should succeed
- ✅ Status badge updates in table

---

## Test Scenario 7: Edit User Extension

### Steps:
1. Find a user extension in the list
2. Click "Edit Extension"
3. Change assigned user
4. Click "Save Changes"

### Expected Request Payload:
```json
{
  "type": "user",
  "status": "active",
  "user_id": 6,
  "voicemail_enabled": false,
  "configuration": {}
}
```

### Assertions:
- ✅ Request SHOULD include `user_id` field
- ✅ User ID should reflect new selection
- ✅ Request should succeed
- ✅ User name updates in table

---

## Test Scenario 8: Validation Error Handling

### Steps:
1. Create extension with existing extension number
2. Observe error handling

### Expected Behavior:
- ✅ Backend returns 422 Unprocessable Entity
- ✅ Error response contains `errors` object:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "extension_number": ["The extension number has already been taken."]
  }
}
```
- ✅ Frontend displays toast with clear error message
- ✅ Dialog remains open for correction
- ✅ User can fix and retry

---

## Test Scenario 9: Empty Configuration Fields

### Steps:
1. Attempt to create conference extension
2. Leave Conference Room dropdown empty
3. Click "Create Extension"

### Expected Behavior:
- ✅ Frontend validation should catch missing field
- ✅ Error message displayed: "Conference room selection is required"
- ✅ Form field highlighted in red
- ✅ No request sent to backend until fixed

---

## Test Scenario 10: Real Data Loading

### Steps:
1. Open "Add Extension" dialog
2. Change type to "Conference Room"
3. Open Conference Room dropdown

### Expected Behavior:
- ✅ Dropdown should show real conference rooms from API
- ✅ Query is only triggered when type changes to 'conference'
- ✅ No mock data displayed
- ✅ If no conference rooms exist, dropdown is empty

---

## Test Scenario 11: Switch Extension Type

### Steps:
1. Open "Add Extension" dialog
2. Select "Conference Room" type
3. Select a conference room
4. Switch type to "Ring Group"
5. Select a ring group
6. Click "Create Extension"

### Expected Request Payload:
```json
{
  "extension_number": "5001",
  "type": "ring_group",
  "status": "active",
  "voicemail_enabled": false,
  "configuration": {
    "ring_group_id": 2
  }
}
```

### Assertions:
- ✅ Request should NOT include `user_id` field
- ✅ Configuration should only contain `ring_group_id`
- ✅ Conference room selection should be cleared/ignored
- ✅ Request should succeed

---

## Test Scenario 12: Network Error Handling

### Steps:
1. Disconnect network or stop backend
2. Attempt to create extension

### Expected Behavior:
- ✅ Error toast displayed with network error message
- ✅ Dialog remains open
- ✅ User can retry when connection restored

---

## Edge Cases to Test

### Edge Case 1: Invalid Integer Parsing
- Try setting conference_room_id to non-numeric value in browser console
- Expected: Should not send NaN, should omit field or show validation error

### Edge Case 2: Rapid Form Submission
- Click "Create Extension" button multiple times rapidly
- Expected: Should only submit once (mutation in progress state)

### Edge Case 3: Large Integer Values
- Test with conference room ID > 2^31
- Expected: Should handle large integers correctly

### Edge Case 4: Special Characters in Fields
- Test extension number with special characters
- Expected: Frontend validation catches invalid format

---

## Regression Testing Checklist

After fixes are applied, verify these still work:
- [ ] Listing extensions with pagination
- [ ] Searching extensions
- [ ] Filtering by type and status
- [ ] Sorting by different columns
- [ ] Deleting extensions
- [ ] Viewing extension details in slide-over
- [ ] Status toggle (activate/deactivate)
- [ ] Permission checks (owner/admin/user roles)
- [ ] Extension detail view
- [ ] Copy extension number

---

## Performance Testing

### Test: Conditional Data Fetching
1. Open "Add Extension" dialog
2. Monitor Network tab
3. Change type between different options

### Expected Behavior:
- ✅ Conference rooms query only runs when type = 'conference'
- ✅ Ring groups query only runs when type = 'ring_group'
- ✅ No unnecessary API calls for other types
- ✅ Queries are cached by React Query

---

## Browser Compatibility

Test in:
- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

---

## Accessibility Testing

- [ ] Keyboard navigation through form
- [ ] Tab order is logical
- [ ] Error messages announced by screen readers
- [ ] Dropdown menus accessible via keyboard
- [ ] Form can be submitted with Enter key
- [ ] Proper ARIA labels on all fields

---

## Manual QA Sign-off

| Test Scenario | Pass/Fail | Tester | Date | Notes |
|---------------|-----------|---------|------|-------|
| Scenario 1: Conference | ☐ | | | |
| Scenario 2: Ring Group | ☐ | | | |
| Scenario 3: User Assigned | ☐ | | | |
| Scenario 4: User Unassigned | ☐ | | | |
| Scenario 5: AI Assistant | ☐ | | | |
| Scenario 6: Edit Conference | ☐ | | | |
| Scenario 7: Edit User | ☐ | | | |
| Scenario 8: Validation Errors | ☐ | | | |
| Scenario 9: Empty Fields | ☐ | | | |
| Scenario 10: Real Data | ☐ | | | |
| Scenario 11: Switch Type | ☐ | | | |
| Scenario 12: Network Error | ☐ | | | |

---

## Debugging Tips

If a test fails:

1. Check browser console for errors
2. Check Network tab for actual request payload
3. Check backend logs for validation errors
4. Verify database has required data (conference rooms, ring groups)
5. Clear browser cache and React Query cache
6. Verify backend validation rules haven't changed
7. Check if API endpoints are accessible
8. Verify authentication token is valid
