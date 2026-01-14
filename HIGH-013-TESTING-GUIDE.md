# HIGH-013 Testing Guide: Resource Reference Checking

**Implementation**: Resource reference checking now prevents deletion of resources that are in use.  
**Commit**: `3c4011f`  
**Date**: 2026-01-14  

---

## What Was Implemented

### New Service: ResourceReferenceChecker
Centralized service that checks if a resource is referenced in:
1. **DID Numbers** (`did_numbers` table) - routing_type + routing_config JSON
2. **IVR Menu Options** (`ivr_menu_options` table) - destination_type + destination_id
3. **IVR Menu Failovers** (`ivr_menus` table) - failover_destination_type + failover_destination_id

### Resources Now Protected
- ✅ **Extensions** - checked in DID routing, IVR options, IVR failovers
- ✅ **Ring Groups** - checked in DID routing, IVR options, IVR failovers
- ✅ **Conference Rooms** - checked in IVR options, IVR failovers
- ✅ **IVR Menus** - checked in DID routing, IVR options, IVR failovers (refactored existing)
- ✅ **Business Hours** - checked in DID routing

### Expected Behavior
- **Referenced resources**: DELETE returns **409 Conflict** with detailed reference information
- **Unreferenced resources**: DELETE returns **204 No Content** (success)

---

## Testing Prerequisites

1. **Get a valid authentication token**:
   ```bash
   # Login to get a token
   curl -X POST http://localhost/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@example.com","password":"password"}'
   
   # Copy the token from the response
   export TOKEN="your_token_here"
   ```

2. **Ensure you have test data**:
   - At least one DID number with routing configured
   - At least one IVR menu with options configured
   - Extensions, ring groups, conference rooms in use

---

## Test Scenarios

### Scenario 1: Delete Extension Referenced by DID

**Setup**: Find an extension that's used in a DID number routing

```bash
# List extensions
curl -X GET "http://localhost/api/v1/extensions" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# List DID numbers to see which extension is referenced
curl -X GET "http://localhost/api/v1/did-numbers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Test**: Try to delete the extension

```bash
# Replace {id} with the extension ID that's referenced
curl -X DELETE "http://localhost/api/v1/extensions/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `409 Conflict`
```json
{
  "error": "Cannot delete extension",
  "message": "This extension is being used and cannot be deleted. Please remove all references first.",
  "references": {
    "did_numbers": [
      {
        "id": 1,
        "phone_number": "+1234567890"
      }
    ]
  }
}
```

---

### Scenario 2: Delete Ring Group Referenced by IVR Menu Option

**Setup**: Find a ring group used in an IVR menu option

```bash
# List ring groups
curl -X GET "http://localhost/api/v1/ring-groups" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# List IVR menus to see options
curl -X GET "http://localhost/api/v1/ivr-menus" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Test**: Try to delete the ring group

```bash
# Replace {id} with the ring group ID that's referenced
curl -X DELETE "http://localhost/api/v1/ring-groups/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `409 Conflict`
```json
{
  "error": "Cannot delete ring_group",
  "message": "This ring_group is being used and cannot be deleted. Please remove all references first.",
  "references": {
    "ivr_menu_options": [
      {
        "ivr_menu_id": 1,
        "ivr_menu_name": "Main Menu",
        "input_digits": "2"
      }
    ]
  }
}
```

---

### Scenario 3: Delete Conference Room Referenced by IVR Failover

**Setup**: Find a conference room used as IVR failover

```bash
# List conference rooms
curl -X GET "http://localhost/api/v1/conference-rooms" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Check IVR menus for failover configurations
curl -X GET "http://localhost/api/v1/ivr-menus/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Test**: Try to delete the conference room

```bash
# Replace {id} with the conference room ID that's referenced
curl -X DELETE "http://localhost/api/v1/conference-rooms/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `409 Conflict`
```json
{
  "error": "Cannot delete conference_room",
  "message": "This conference_room is being used and cannot be deleted. Please remove all references first.",
  "references": {
    "ivr_failovers": [
      {
        "id": 1,
        "ivr_menu_name": "Support Menu"
      }
    ]
  }
}
```

---

### Scenario 4: Delete IVR Menu Referenced by DID and Other IVR

**Setup**: Find an IVR menu that's used in DID routing or as option in another IVR

```bash
# List IVR menus
curl -X GET "http://localhost/api/v1/ivr-menus" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Check DID numbers
curl -X GET "http://localhost/api/v1/did-numbers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Test**: Try to delete the IVR menu

```bash
# Replace {id} with the IVR menu ID that's referenced
curl -X DELETE "http://localhost/api/v1/ivr-menus/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `409 Conflict` (could have multiple reference types)
```json
{
  "error": "Cannot delete ivr_menu",
  "message": "This ivr_menu is being used and cannot be deleted. Please remove all references first.",
  "references": {
    "did_numbers": [
      {
        "id": 1,
        "phone_number": "+1234567890"
      }
    ],
    "ivr_menu_options": [
      {
        "ivr_menu_id": 2,
        "ivr_menu_name": "Main Menu",
        "input_digits": "3"
      }
    ]
  }
}
```

---

### Scenario 5: Delete Business Hours Schedule Referenced by DID

**Setup**: Find a business hours schedule used in DID routing

```bash
# List business hours schedules
curl -X GET "http://localhost/api/v1/business-hours" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# Check DID numbers with business_hours routing
curl -X GET "http://localhost/api/v1/did-numbers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Test**: Try to delete the business hours schedule

```bash
# Replace {id} with the business hours schedule ID that's referenced
curl -X DELETE "http://localhost/api/v1/business-hours/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `409 Conflict`
```json
{
  "error": "Cannot delete business_hours",
  "message": "This business_hours is being used and cannot be deleted. Please remove all references first.",
  "references": {
    "did_numbers": [
      {
        "id": 1,
        "phone_number": "+1234567890"
      }
    ]
  }
}
```

---

### Scenario 6: Delete Unreferenced Resource (Success Case)

**Setup**: Create a new test resource that's not referenced anywhere

```bash
# Create a test conference room
curl -X POST "http://localhost/api/v1/conference-rooms" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "room_number": "9999",
    "name": "Test Conference Room",
    "pin": "1234",
    "max_participants": 10,
    "status": "active"
  }'

# Note the ID from the response
```

**Test**: Delete the unreferenced resource

```bash
# Replace {id} with the test conference room ID
curl -X DELETE "http://localhost/api/v1/conference-rooms/{id}" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -v
```

**Expected Result**: `204 No Content` (successful deletion, no body)

---

## Testing via Frontend UI

### Test Extension Deletion
1. Go to **Extensions** page
2. Find an extension that's used in a DID or IVR menu
3. Click the delete button
4. **Expected**: Error dialog showing where the extension is used

### Test Ring Group Deletion
1. Go to **Ring Groups** page
2. Find a ring group that's used in an IVR menu option
3. Click the delete button
4. **Expected**: Error dialog showing references

### Test Conference Room Deletion
1. Go to **Conference Rooms** page
2. Find a conference room used as IVR failover
3. Click the delete button
4. **Expected**: Error dialog showing references

### Test IVR Menu Deletion
1. Go to **IVR Menus** page
2. Find an IVR menu that's used in DID routing
3. Click the delete button
4. **Expected**: Error dialog showing references

---

## Verification Checklist

- [ ] Extensions referenced by DIDs cannot be deleted (409)
- [ ] Extensions referenced by IVR options cannot be deleted (409)
- [ ] Extensions referenced by IVR failovers cannot be deleted (409)
- [ ] Ring groups referenced by DIDs cannot be deleted (409)
- [ ] Ring groups referenced by IVR options cannot be deleted (409)
- [ ] Ring groups referenced by IVR failovers cannot be deleted (409)
- [ ] Conference rooms referenced by IVR options cannot be deleted (409)
- [ ] Conference rooms referenced by IVR failovers cannot be deleted (409)
- [ ] IVR menus referenced by DIDs cannot be deleted (409)
- [ ] IVR menus referenced by other IVR options cannot be deleted (409)
- [ ] IVR menus referenced by other IVR failovers cannot be deleted (409)
- [ ] Business hours schedules referenced by DIDs cannot be deleted (409)
- [ ] Unreferenced resources can be deleted successfully (204)
- [ ] Error messages include specific reference details
- [ ] Tenant isolation works (can only see references in same organization)
- [ ] Existing functionality still works (no regressions)

---

## Troubleshooting

### Issue: Getting 500 errors instead of 409
**Check**: App container logs
```bash
docker logs opbx_app --tail 50
```
Look for PHP errors or exceptions.

### Issue: Getting 204 when expecting 409
**Possible causes**:
1. Resource is not actually referenced (check database)
2. Reference checker query syntax issue (check logs)
3. Tenant isolation filtering out references (verify organization_id)

### Issue: Getting 409 when expecting 204
**Possible causes**:
1. Resource is referenced but not visible in UI
2. Stale references in database (orphaned records)
3. Need to refresh DID/IVR data

### Check Database Directly
```bash
# Connect to database container
docker exec -it opbx_mysql mysql -u opbx_user -p opbx_db

# Check DID references
SELECT id, phone_number, routing_type, routing_config 
FROM did_numbers 
WHERE routing_type = 'extension' AND JSON_EXTRACT(routing_config, '$.extension_id') = 1;

# Check IVR option references
SELECT imo.id, im.name as ivr_menu_name, imo.input_digits, imo.destination_type, imo.destination_id
FROM ivr_menu_options imo
JOIN ivr_menus im ON imo.ivr_menu_id = im.id
WHERE imo.destination_type = 'ring_group' AND imo.destination_id = 1;

# Check IVR failover references
SELECT id, name, failover_destination_type, failover_destination_id
FROM ivr_menus
WHERE failover_destination_type = 'conference_room' AND failover_destination_id = 1;
```

---

## Success Criteria

✅ **Implementation is successful if**:
1. All referenced resources return 409 with detailed references
2. All unreferenced resources delete successfully with 204
3. Error messages are clear and actionable
4. No existing functionality is broken
5. Frontend UI shows appropriate error dialogs
6. Tenant isolation is maintained
7. No PHP errors in logs

---

## Next Steps After Validation

1. ✅ Mark HIGH-013 as **COMPLETED**
2. Update `CODE_REVIEW_HIGH-013_TECHNICAL_DEBT.md` → Mark as **IMPLEMENTED**
3. Update `CODE_REVIEW_HIGH_ISSUES_SUMMARY.md` with HIGH-013 completion
4. Continue with remaining MEDIUM priority issues

---

**Status**: ⏳ AWAITING VALIDATION  
**Tester**: Manual validation required  
**Blockers**: None - containers restarted, code deployed
