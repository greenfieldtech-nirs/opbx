# Issue #1: Syntax Error in API Client

**Status:** Pending
**Priority:** Critical
**Estimated Effort:** 15 minutes
**Assigned:** Unassigned

## Problem Description

The frontend API client (`frontend/src/services/api.ts`) contains a syntax error on lines 30-34 where a closing brace is missing in the request interceptor. This prevents the frontend from compiling and running.

**Location:** `frontend/src/services/api.ts:30-34`

**Current Code:**
```typescript
if (config.data instanceof FormData) {
  delete config.headers['Content-Type'];
}    } // <- Missing closing brace
```

## Impact Assessment

- **Severity:** Critical - Blocks compilation
- **Scope:** Frontend development completely halted
- **Risk:** High - Prevents any frontend work
- **Dependencies:** None

## Solution Overview

Fix the missing closing brace in the axios request interceptor.

## Implementation Steps

### Step 1: Locate the Error
1. Open `frontend/src/services/api.ts`
2. Navigate to lines 30-34
3. Identify the missing closing brace

### Step 2: Fix the Syntax Error
1. Add the missing closing brace after the FormData check
2. Verify the brace structure matches the opening brace

### Step 3: Test the Fix
1. Run TypeScript compilation: `npm run build`
2. Start development server: `npm run dev`
3. Verify API calls work correctly

## Code Changes

### File: `frontend/src/services/api.ts`
**Before:**
```typescript
if (config.data instanceof FormData) {
  delete config.headers['Content-Type'];
}    } // <- This line has extra brace
```

**After:**
```typescript
if (config.data instanceof FormData) {
  delete config.headers['Content-Type'];
}
// <- Remove the extra brace
```

## Verification Steps

1. **Compilation Test:**
   ```bash
   cd frontend
   npm run build
   ```
   Expected: No compilation errors

2. **Runtime Test:**
   ```bash
   npm run dev
   ```
   Expected: Development server starts successfully

3. **API Test:**
   - Open browser console
   - Make an API call
   - Verify no JavaScript errors

## Rollback Plan

If the fix introduces issues:
1. Revert the change
2. Double-check the brace structure
3. Consider reformatting the entire interceptor

## Testing Requirements

- [ ] TypeScript compilation successful
- [ ] Frontend development server starts
- [ ] API requests work without errors
- [ ] No console errors in browser

## Documentation Updates

- Update this implementation plan with actual time spent
- Mark as completed in master work plan
- Document any issues encountered

## Completion Criteria

- [ ] Syntax error fixed
- [ ] Frontend compiles successfully
- [ ] No runtime errors
- [ ] Code reviewed and approved

---

**Estimated Completion:** 15 minutes
**Actual Time Spent:** ____ minutes
**Completed By:** ____________
**Date:** ____________