# HIGH-013 Technical Debt: IVR Reference Checking Could Be Generalized

**Status**: 📋 **DOCUMENTED AS TECHNICAL DEBT**  
**Severity**: LOW  
**Priority**: P3 (Nice-to-Have)  
**Date**: 2026-01-14  

## Issue Description

The IvrMenuController.destroy() method (lines 484-541) contains complex reference checking logic to prevent deletion of IVR menus that are actively referenced elsewhere:

1. **Referenced by other IVR menu options** (as destination)
2. **Referenced by IVR failover configuration** 
3. **Referenced by DID number routing**

This pattern **should** be applied to other resources (Extensions, RingGroups, ConferenceRooms) that can also be referenced via polymorphic relationships, but currently is only implemented for IVR menus.

## Current Implementation

### IvrMenuController.destroy() (58 lines)

```php
// Check if IVR menu is referenced by other IVR menus (lines 485-492)
$referencingMenus = DB::table('ivr_menu_options')
    ->join('ivr_menus', 'ivr_menu_options.ivr_menu_id', '=', 'ivr_menus.id')
    ->where('ivr_menu_options.destination_type', 'ivr_menu')
    ->where('ivr_menu_options.destination_id', $ivrMenu->id)
    ->where('ivr_menus.organization_id', $user->organization_id)
    ->select('ivr_menus.id', 'ivr_menus.name')
    ->distinct()
    ->get();

// Check if IVR menu is used as failover in other menus (lines 495-500)
$failoverMenus = IvrMenu::where('organization_id', $user->organization_id)
    ->where('failover_destination_type', 'ivr_menu')
    ->where('failover_destination_id', $ivrMenu->id)
    ->where('id', '!=', $ivrMenu->id)
    ->select('id', 'name')
    ->get();

// Check if IVR menu is referenced by DID routing (lines 503-508)
$referencingDids = DB::table('did_numbers')
    ->where('routing_type', 'ivr_menu')
    ->where('routing_config->ivr_menu_id', $ivrMenu->id)
    ->where('organization_id', $user->organization_id)
    ->select('id', 'phone_number')
    ->get();

// Collect and return references (lines 510-541)
if ($hasReferences) {
    $references = [];
    // ... map collections to arrays
    return response()->json([
        'error' => 'Cannot delete IVR menu',
        'message' => 'This IVR menu is being used and cannot be deleted. Please remove all references first.',
        'references' => $references,
    ], 409);
}
```

## Polymorphic Relationships in Schema

Resources that can be referenced (based on database migrations):

### did_numbers.routing_type
- `extension`
- `ring_group`
- `business_hours`
- `conference_room`
- `ivr_menu` ✅ (has reference checking)

### ivr_menus.failover_destination_type
- `extension`
- `ring_group`
- `conference_room`
- `ivr_menu` ✅ (has reference checking)
- `hangup` (no reference needed)

### ivr_menu_options.destination_type
- `extension`
- `ring_group`
- `conference_room`
- `ivr_menu` ✅ (has reference checking)

### Resources Needing Reference Checking

| Resource | Referenced By | Current Status |
|----------|---------------|----------------|
| IvrMenu | did_numbers, ivr_menus (failover), ivr_menu_options | ✅ Implemented |
| Extension | did_numbers, ivr_menus (failover), ivr_menu_options | ❌ Missing |
| RingGroup | did_numbers, ivr_menus (failover), ivr_menu_options | ❌ Missing |
| ConferenceRoom | ivr_menus (failover), ivr_menu_options | ❌ Missing |
| BusinessHours | did_numbers | ❌ Missing |

## Why This Is Technical Debt (Not Urgent)

### Reasons for LOW Priority

1. **Database Integrity Likely Protected**
   - Laravel soft deletes may be in use
   - Foreign key constraints may exist
   - Cascading deletes may be configured

2. **Limited User Impact**
   - Deleting a referenced resource would cause runtime errors, not data corruption
   - Errors would be caught during call routing (graceful degradation likely exists)
   - Admin users typically test deletions in development first

3. **IVR Menus Are Most Complex**
   - IVR menus can reference themselves (recursive)
   - Extensions/RingGroups have simpler reference patterns
   - The most complex case is already handled

4. **Implementation Cost Is High**
   - Requires designing a generic reference checking service
   - Each resource type has different reference patterns
   - Testing burden is significant (need to test all combinations)

5. **Current Workaround Exists**
   - Admins can manually check usage before deletion
   - UI could show "Used By" information on detail pages
   - Database errors will prevent true deletion if constraints exist

## Proposed Future Solution

When/if this is prioritized, implement a **Resource Reference Checker Service**:

### Design Approach 1: Service Class

```php
// app/Services/ResourceReferenceChecker.php
namespace App\Services;

class ResourceReferenceChecker
{
    /**
     * Check if a resource is referenced elsewhere
     * 
     * @param string $resourceType 'extension'|'ring_group'|'ivr_menu'|'conference_room'
     * @param int $resourceId
     * @param int $organizationId
     * @return array ['has_references' => bool, 'references' => array]
     */
    public function checkReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        $references = [];

        // Check DID routing references
        $references['did_numbers'] = $this->checkDidReferences($resourceType, $resourceId, $organizationId);

        // Check IVR menu option references
        $references['ivr_menu_options'] = $this->checkIvrOptionReferences($resourceType, $resourceId, $organizationId);

        // Check IVR failover references
        $references['ivr_failovers'] = $this->checkIvrFailoverReferences($resourceType, $resourceId, $organizationId);

        // Remove empty reference arrays
        $references = array_filter($references, fn($refs) => !empty($refs));

        return [
            'has_references' => !empty($references),
            'references' => $references,
        ];
    }

    private function checkDidReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        return DB::table('did_numbers')
            ->where('routing_type', $this->mapResourceTypeToRoutingType($resourceType))
            ->where('routing_config->id', $resourceId) // Adjust based on actual JSON structure
            ->where('organization_id', $organizationId)
            ->select('id', 'phone_number')
            ->get()
            ->toArray();
    }

    private function checkIvrOptionReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        return DB::table('ivr_menu_options')
            ->join('ivr_menus', 'ivr_menu_options.ivr_menu_id', '=', 'ivr_menus.id')
            ->where('ivr_menu_options.destination_type', $resourceType)
            ->where('ivr_menu_options.destination_id', $resourceId)
            ->where('ivr_menus.organization_id', $organizationId)
            ->select('ivr_menus.id', 'ivr_menus.name', 'ivr_menu_options.input_digits')
            ->distinct()
            ->get()
            ->toArray();
    }

    private function checkIvrFailoverReferences(string $resourceType, int $resourceId, int $organizationId): array
    {
        return DB::table('ivr_menus')
            ->where('failover_destination_type', $resourceType)
            ->where('failover_destination_id', $resourceId)
            ->where('organization_id', $organizationId)
            ->select('id', 'name')
            ->get()
            ->toArray();
    }

    private function mapResourceTypeToRoutingType(string $resourceType): string
    {
        return match($resourceType) {
            'extension' => 'extension',
            'ring_group' => 'ring_group',
            'ivr_menu' => 'ivr_menu',
            'conference_room' => 'conference_room',
            'business_hours' => 'business_hours',
            default => throw new \InvalidArgumentException("Unknown resource type: $resourceType"),
        };
    }
}
```

### Design Approach 2: Trait + Hook in AbstractApiCrudController

```php
// app/Http/Controllers/Traits/ChecksResourceReferences.php
trait ChecksResourceReferences
{
    protected function checkResourceReferences(Model $model, string $resourceType): ?JsonResponse
    {
        $checker = app(ResourceReferenceChecker::class);
        $result = $checker->checkReferences($resourceType, $model->id, $model->organization_id);

        if ($result['has_references']) {
            return response()->json([
                'error' => 'Cannot delete ' . $resourceType,
                'message' => 'This ' . $resourceType . ' is being used and cannot be deleted. Please remove all references first.',
                'references' => $result['references'],
            ], 409);
        }

        return null; // No references, safe to delete
    }
}

// In AbstractApiCrudController
protected function beforeDestroy(Model $model, Request $request): void
{
    // Hook for subclasses to add pre-delete logic
    // Subclass can throw exception or abort(409) if references exist
}

// In IvrMenuController (and other controllers)
protected function beforeDestroy(Model $model, Request $request): void
{
    $response = $this->checkResourceReferences($model, 'ivr_menu');
    if ($response) {
        // How to return response from hook? Need to refactor AbstractApiCrudController
        throw new ResourceInUseException($response);
    }
}
```

### Recommendation

**Approach 1 (Service Class)** is cleaner and more testable, but requires modifying each controller's destroy method to call the service.

**Approach 2 (Trait + Hook)** is more DRY but requires refactoring AbstractApiCrudController to handle early returns from hooks.

## Acceptance Criteria (Future Implementation)

When this is implemented, ensure:

1. ✅ All resources with polymorphic references have reference checking
2. ✅ Reference checking is centralized in a service class
3. ✅ Error responses are consistent across all resources
4. ✅ Response includes specific references (not just "in use")
5. ✅ Unit tests cover all reference patterns
6. ✅ Integration tests verify 409 responses
7. ✅ Performance is acceptable (queries should be fast with proper indexes)
8. ✅ Tenant isolation is enforced (only check within organization)
9. ✅ Documentation explains reference checking behavior

## Estimated Effort

- **Design & Planning**: 2-4 hours
- **Service Implementation**: 4-6 hours
- **Controller Integration**: 2-4 hours (depending on approach)
- **Unit Tests**: 4-6 hours
- **Integration Tests**: 4-6 hours
- **Documentation**: 1-2 hours

**Total**: ~17-28 hours (2-3.5 days)

## Conclusion

HIGH-013 is **valid technical debt** but does not require immediate action. The current implementation for IVR menus provides good UX, and extending it to all resources is a nice-to-have that can be prioritized in a future sprint focused on code quality improvements.

**Recommended Priority**: P3 (implement after all HIGH/MEDIUM issues are resolved)

**Next Steps**:
1. Document in backlog
2. Continue with remaining HIGH priority issues (HIGH-014, HIGH-015)
3. Revisit after code review completion if time permits
