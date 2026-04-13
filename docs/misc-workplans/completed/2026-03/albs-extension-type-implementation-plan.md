# AI Load Balancer Extension Type - Implementation Plan

## Overview
Add a new extension type "AI Load Balancer" that routes calls to an AI Assistant Load Balancer using distribution algorithms (Round Robin, Priority, Percentage).

## Implementation Phases

### Phase 1: Backend - ExtensionType Enum & Model (Estimated: 15 mins)
**Files to modify:**
- `app/Enums/ExtensionType.php` - Add AI_LOAD_BALANCER case

**Changes:**
1. Add enum case: `case AI_LOAD_BALANCER = 'ai_load_balancer'`
2. Update `label()` method to return 'AI Load Balancer'
3. Update `description()` method
4. Update `requiredConfigFields()` to return `['ai_load_balancer_id']`

**Testing:**
- Verify enum values and labels
- Run: `php artisan test --filter ExtensionTypeTest` (if exists)

**Commit:** `feat: add AI_LOAD_BALANCER extension type enum`

---

### Phase 2: Backend - Extension Model Relation (Estimated: 10 mins)
**Files to modify:**
- `app/Models/Extension.php`

**Changes:**
1. Add relationship method:
```php
public function aiLoadBalancer(): BelongsTo
{
    return $this->belongsTo(AiAssistantLoadBalancer::class, 'configuration->ai_load_balancer_id');
}
```

**Testing:**
- Verify relationship loads correctly

**Commit:** `feat: add aiLoadBalancer relation to Extension model`

---

### Phase 3: Backend - Extension Controller Validation (Estimated: 20 mins)
**Files to modify:**
- `app/Http/Controllers/Api/ExtensionController.php`

**Changes:**
1. Add validation rule for `ai_load_balancer_id` in store/update methods
2. Update `buildIndexQuery()` to eager load `aiLoadBalancer` relation
3. Ensure configuration is properly saved

**Testing:**
- Test API validation rejects missing ai_load_balancer_id
- Test API accepts valid ai_load_balancer_id

**Commit:** `feat: add validation for ai_load_balancer extension type`

---

### Phase 4: Backend - Extension Resource (Estimated: 15 mins)
**Files to modify:**
- `app/Http/Resources/ExtensionResource.php`

**Changes:**
1. Add ai_load_balancer data to resource transformation:
```php
'ai_load_balancer' => $this->when(
    $this->type === ExtensionType::AI_LOAD_BALANCER && $this->aiLoadBalancer,
    fn() => [
        'id' => $this->aiLoadBalancer->id,
        'name' => $this->aiLoadBalancer->name,
    ]
),
```

**Testing:**
- Verify API response includes ai_load_balancer data

**Commit:** `feat: include ai_load_balancer in ExtensionResource`

---

### Phase 5: Backend - Voice Routing Strategy (Estimated: 45 mins)
**Files to create:**
- `app/Services/VoiceRouting/Strategies/AiLoadBalancerRoutingStrategy.php`

**Implementation details:**
1. Implement `RoutingStrategy` interface
2. `canHandle()` returns true for AI_LOAD_BALANCER type
3. `route()` method:
   - Extract load balancer from destination
   - Call `AlbsDistributionService->selectAssistant()`
   - If successful, delegate to `AiAgentRoutingStrategy`
   - If failed, execute fallback action from load balancer
4. Implement fallback handlers (extension, ring_group, ivr_menu, ai_assistant, hangup)

**Testing:**
- Test strategy selection logic
- Test fallback scenarios
- Run voice routing tests

**Commit:** `feat: implement AiLoadBalancerRoutingStrategy`

---

### Phase 6: Backend - Register Strategy (Estimated: 10 mins)
**Files to modify:**
- `app/Services/VoiceRouting/VoiceRoutingManager.php`

**Changes:**
1. Add `AiLoadBalancerRoutingStrategy` to strategies array

**Testing:**
- Verify strategy is used for ai_load_balancer extensions

**Commit:** `feat: register AiLoadBalancerRoutingStrategy in VoiceRoutingManager`

---

### Phase 7: Backend - Tests (Estimated: 30 mins)
**Files to create:**
- `tests/Unit/Services/VoiceRouting/AiLoadBalancerRoutingStrategyTest.php`

**Test cases:**
1. Strategy can handle AI_LOAD_BALANCER type
2. Route selects AI assistant using distribution service
3. Route delegates to AiAgentRoutingStrategy
4. Fallback executed when no members available
5. Fallback to extension works
6. Fallback to hangup works

**Commit:** `test: add unit tests for AiLoadBalancerRoutingStrategy`

---

### Phase 8: Frontend - Types (Estimated: 10 mins)
**Files to modify:**
- `frontend/src/types/index.ts`

**Changes:**
1. Update `ExtensionType` to include `'ai_load_balancer'`
2. Update `Extension` interface to include optional `ai_load_balancer` property

**Testing:**
- TypeScript compilation passes

**Commit:** `feat: add ai_load_balancer to ExtensionType`

---

### Phase 9: Frontend - Extensions Page - Form Data (Estimated: 10 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add `ai_load_balancer_id: string` to `ExtensionFormData` interface
2. Initialize in `emptyFormData`

**Commit:** `feat: add ai_load_balancer_id to ExtensionFormData`

---

### Phase 10: Frontend - Extensions Page - Type Configuration (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add type badge configuration for `ai_load_balancer`:
   - Label: 'AI Load Balancer'
   - Color: 'bg-cyan-100 text-cyan-800 border-cyan-200'
   - Icon: Scale

**Testing:**
- Verify badge displays correctly in table

**Commit:** `feat: add ai_load_balancer badge configuration`

---

### Phase 11: Frontend - Extensions Page - Validation (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add validation case for 'ai_load_balancer':
```typescript
case 'ai_load_balancer':
  if (!formData.ai_load_balancer_id) {
    errors.ai_load_balancer_id = 'AI Load Balancer selection is required';
  }
  break;
```

**Testing:**
- Verify validation prevents save without selection

**Commit:** `feat: add validation for ai_load_balancer extension type`

---

### Phase 12: Frontend - Extensions Page - Form Preparation (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add configuration handling in `handleCreate`:
```typescript
case 'ai_load_balancer':
  if (formData.ai_load_balancer_id) {
    configuration.ai_load_balancer_id = parseInt(formData.ai_load_balancer_id, 10);
  }
  break;
```
2. Add same handling in `handleUpdate`

**Commit:** `feat: add configuration handling for ai_load_balancer`

---

### Phase 13: Frontend - Extensions Page - Type Selector (Estimated: 10 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add `<SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>` in:
   - Create dialog type selector
   - Edit dialog type selector
   - Bulk update type selector

**Testing:**
- Verify option appears in dropdowns

**Commit:** `feat: add ai_load_balancer to type selectors`

---

### Phase 14: Frontend - Extensions Page - Form Fields (Estimated: 30 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add form section for 'ai_load_balancer' type:
   - Fetch AI Load Balancers via React Query
   - Render Select dropdown with available load balancers
   - Show "No AI Load Balancers available" message if empty
   - Handle loading and error states

**Implementation:**
```typescript
const { data: loadBalancersData } = useQuery({
  queryKey: ['ai-assistant-load-balancers', { status: 'active', per_page: 100 }],
  queryFn: () => aiAssistantLoadBalancersService.getAll({ status: 'active', per_page: 100 }),
});
```

**Testing:**
- Verify form renders correctly
- Verify selection works
- Verify validation messages display

**Commit:** `feat: add ai_load_balancer form fields to Extensions page`

---

### Phase 15: Frontend - Extensions Page - Edit Dialog (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Update `openEditDialog` to populate `ai_load_balancer_id` from extension configuration

**Commit:** `feat: populate ai_load_balancer_id in edit dialog`

---

### Phase 16: Frontend - Extensions Page - Detail View (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add display logic for `ai_load_balancer` type in detail sheet:
   - Show load balancer name with Scale icon
   - Link to load balancer (if applicable)

**Commit:** `feat: display ai_load_balancer info in detail sheet`

---

### Phase 17: Frontend - Extensions Page - Table Cell (Estimated: 10 mins)
**Files to modify:**
- `frontend/src/pages/Extensions.tsx`

**Changes:**
1. Add case for 'ai_load_balancer' in `getTypeBadge()`:
   - Badge with cyan color scheme
   - Scale icon
   - Label: 'AI Load Balancer'

**Testing:**
- Verify badge displays in table

**Commit:** `feat: add ai_load_balancer type badge to table`

---

### Phase 18: Frontend - Phone Numbers Page (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/PhoneNumbers.tsx`

**Changes:**
1. Add 'ai_load_balancer' as a destination type option
2. Add selection logic for load balancer
3. Add display logic for load balancer destinations

**Commit:** `feat: add ai_load_balancer destination to Phone Numbers`

---

### Phase 19: Frontend - IVR Menus Page (Estimated: 15 mins)
**Files to modify:**
- `frontend/src/pages/IVRMenus.tsx`

**Changes:**
1. Add 'ai_load_balancer' as a destination type option for menu options
2. Add 'ai_load_balancer' as a destination type option for failover
3. Add selection and display logic

**Commit:** `feat: add ai_load_balancer destination to IVR Menus`

---

### Phase 20: Testing & Validation (Estimated: 30 mins)
**Backend Tests:**
```bash
podman-compose exec app php artisan test tests/Unit/Services/VoiceRouting/AiLoadBalancerRoutingStrategyTest.php
podman-compose exec app php artisan test tests/Unit/Enums/ExtensionTypeTest.php  # if exists
```

**Frontend Tests:**
```bash
podman-compose exec frontend npm run type-check
podman-compose exec frontend npm run lint
```

**Integration Tests:**
1. Create AI Load Balancer extension via API
2. Verify extension routes to correct AI Assistant
3. Verify fallback works when no members available
4. Verify frontend displays correctly

**Commit:** `test: validate ai_load_balancer extension type implementation`

---

## Summary

**Total Estimated Time:** ~4.5 hours
**Total Commits:** ~20 commits
**Files Created:** 2 (routing strategy + test)
**Files Modified:** ~8 files

## Rollback Plan

If issues are discovered:
1. Revert commits in reverse order
2. Remove strategy registration first (prevents new routing)
3. Remove frontend changes
4. Remove backend changes
5. Database changes use `configuration` JSON field - no migration needed

## Dependencies

- Existing AI Load Balancer feature (already implemented)
- AlbsDistributionService (already exists)
- AiAgentRoutingStrategy (already exists)
- Extension CRUD infrastructure (already exists)
