# Issue #14: Magic Strings Instead of Enums

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 3-4 hours
**Assigned:** Unassigned

## Problem Description

Status values and roles use magic strings instead of enums, leading to type safety issues and maintenance difficulty.

**Location:** `app/Models/Organization.php:116`, `app/Models/User.php:126`

## Impact Assessment

- **Severity:** Important - Type safety and maintainability
- **Scope:** All models using status/role values
- **Risk:** Medium - Runtime errors from invalid values
- **Dependencies:** Model classes, database migrations

## Solution Overview

Replace magic strings with proper enum classes and update all usage throughout the codebase.

## Implementation Steps

### Phase 1: Create Enum Classes (1 hour)
1. Create `OrganizationStatus` enum
2. Create `UserRole` and `UserStatus` enums (if not existing)
3. Define all valid values

### Phase 2: Update Models (1 hour)
1. Replace magic strings in models
2. Update casts and accessors
3. Ensure backward compatibility

### Phase 3: Update Database Migrations (1 hour)
1. Add enum constraints to database
2. Update existing data
3. Create migration for enum changes

### Phase 4: Update Usage Throughout Codebase (1-2 hours)
1. Find all magic string usages
2. Replace with enum values
3. Update validation rules
4. Update tests

## Code Changes

### New File: `app/Enums/OrganizationStatus.php`
```php
<?php

namespace App\Enums;

enum OrganizationStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case DELETED = 'deleted';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
```

### File: `app/Models/Organization.php`
**Before:**
```php
public function isActive(): bool
{
    return $this->status === 'active';
}
```

**After:**
```php
protected function casts(): array
{
    return [
        'status' => OrganizationStatus::class,
    ];
}

public function isActive(): bool
{
    return $this->status === OrganizationStatus::ACTIVE;
}
```

### Database Migration:
```php
Schema::table('organizations', function (Blueprint $table) {
    $table->enum('status', array_column(OrganizationStatus::cases(), 'value'))
          ->default(OrganizationStatus::ACTIVE->value)
          ->change();
});
```

## Verification Steps

1. **Enum Usage Test:**
   ```php
   $org = Organization::first();
   $org->status = OrganizationStatus::SUSPENDED;
   $org->save();
   ```

2. **Validation Test:**
   ```php
   // Should fail with invalid status
   $org->status = 'invalid';
   ```

3. **Database Constraint Test:**
   - Verify enum constraints in database
   - Test migration rollback

## Rollback Plan

If enum migration fails:
1. Keep magic strings temporarily
2. Implement gradual migration
3. Use constants instead of enums initially

## Testing Requirements

- [ ] All enum values work correctly
- [ ] Invalid values rejected
- [ ] Database constraints enforced
- [ ] Backward compatibility maintained

## Documentation Updates

- Document enum usage patterns
- Update model documentation
- Mark as completed in master work plan

## Completion Criteria

- [ ] Magic strings replaced with enums
- [ ] Type safety improved
- [ ] Database constraints added
- [ ] Code reviewed and approved

---

**Estimated Completion:** 3-4 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #15: Large Class Violation (VoiceRoutingManager)

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 8-12 hours
**Assigned:** Unassigned

## Problem Description

`VoiceRoutingManager` is 734 lines and violates Single Responsibility Principle by handling routing, business hours, IVR input, and strategy execution.

**Location:** `app/Services/VoiceRoutingManager.php` (734 lines)

## Impact Assessment

- **Severity:** Important - Architecture violation
- **Scope:** Core call routing functionality
- **Risk:** Medium - Difficult to maintain and extend
- **Dependencies:** Cloudonix integration, call routing logic

## Solution Overview

Break down VoiceRoutingManager into focused, single-responsibility services.

## Target Architecture

```
VoiceRoutingManager (orchestrator - 100-150 lines)
├── DidResolver (DID number resolution)
├── ExtensionResolver (extension lookup)
├── BusinessHoursChecker (schedule validation)
├── IvrInputHandler (IVR menu processing)
├── CallStateManager (call state tracking)
└── RoutingStrategy (strategy pattern for routing)
```

## Implementation Steps

### Phase 1: Analysis and Design (2 hours)
1. Map current responsibilities
2. Design new service interfaces
3. Plan dependency injection

### Phase 2: Create Focused Services (4-6 hours)
1. Extract `DidResolver` service
2. Extract `ExtensionResolver` service
3. Extract `BusinessHoursChecker` service
4. Extract `IvrInputHandler` service
5. Create `RoutingStrategy` interface

### Phase 3: Refactor Main Manager (2-3 hours)
1. Update VoiceRoutingManager to use new services
2. Implement dependency injection
3. Maintain existing API compatibility

### Phase 4: Testing and Integration (1-2 hours)
1. Test all routing scenarios
2. Verify Cloudonix integration
3. Performance testing

## Code Changes

### New File: `app/Services/DidResolver.php`
```php
<?php

namespace App\Services;

class DidResolver
{
    public function resolve(string $didNumber): ?DidNumber
    {
        return DidNumber::with(['organization', 'routing'])
            ->where('number', $didNumber)
            ->where('organization_id', auth()->user()->organization_id)
            ->first();
    }
}
```

### New File: `app/Services/BusinessHoursChecker.php`
```php
<?php

namespace App\Services;

use Carbon\Carbon;

class BusinessHoursChecker
{
    public function isWithinBusinessHours(DidNumber $did): bool
    {
        $now = Carbon::now();
        $schedule = $did->businessHours;
        
        if (!$schedule) {
            return true; // Default to always open
        }
        
        // Implement business hours logic
        return $this->checkSchedule($schedule, $now);
    }
}
```

### New File: `app/Contracts/RoutingStrategy.php`
```php
<?php

namespace App\Contracts;

interface RoutingStrategy
{
    public function execute(DidNumber $did, array $context): CxmlResponse;
}
```

### File: `app/Services/VoiceRoutingManager.php`
**Before:** 734 lines with mixed responsibilities
**After:** ~100 lines orchestrating focused services

```php
class VoiceRoutingManager
{
    public function __construct(
        private DidResolver $didResolver,
        private ExtensionResolver $extensionResolver,
        private BusinessHoursChecker $businessHoursChecker,
        private IvrInputHandler $ivrHandler,
    ) {}

    public function routeInboundCall(array $webhookData): CxmlResponse
    {
        $did = $this->didResolver->resolve($webhookData['did']);
        
        if ($this->businessHoursChecker->isWithinBusinessHours($did)) {
            return $this->routeToExtension($did, $webhookData);
        }
        
        return $this->handleAfterHours($did, $webhookData);
    }
}
```

## Verification Steps

1. **Functionality Test:**
   - Test all routing scenarios
   - Verify CXML responses
   - Check Cloudonix integration

2. **Unit Test Coverage:**
   ```bash
   php artisan test --filter=VoiceRoutingManagerTest
   ```

3. **Integration Test:**
   - End-to-end call routing test
   - Webhook processing verification

## Rollback Plan

If refactoring breaks functionality:
1. Keep original VoiceRoutingManager as backup
2. Gradually migrate responsibilities
3. Test each service independently

## Testing Requirements

- [ ] All routing scenarios work
- [ ] CXML responses correct
- [ ] Cloudonix integration maintained
- [ ] Unit tests for each service
- [ ] Integration tests pass

## Documentation Updates

- Document new service architecture
- Update class diagrams
- Mark as completed in master work plan

## Completion Criteria

- [ ] VoiceRoutingManager decomposed
- [ ] Single responsibility services created
- [ ] All functionality preserved
- [ ] Code reviewed and approved

---

**Estimated Completion:** 8-12 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #16: Inconsistent Method Signatures

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

Some methods use loose comparison (`!=`) instead of strict (`!==`), potentially causing type coercion bugs.

**Location:** Various controllers and services

## Impact Assessment

- **Severity:** Normal - Code quality issue
- **Scope:** Method implementations across codebase
- **Risk:** Low - Potential runtime bugs
- **Dependencies:** PHP type system

## Solution Overview

Replace all loose comparisons with strict comparisons throughout the codebase.

## Implementation Steps

### Step 1: Code Analysis (30 minutes)
1. Find all loose comparison usages
2. Categorize by risk level
3. Plan replacement strategy

### Step 2: Replace Comparisons (1-2 hours)
1. Update controllers and services
2. Update model methods
3. Update helper functions

### Step 3: Add Static Analysis (30 minutes)
1. Configure PHPStan or Psalm
2. Add strict comparison rules
3. Run analysis on codebase

### Step 4: Testing (30 minutes)
1. Run test suite
2. Check for type-related issues
3. Verify functionality preserved

## Code Changes

### Examples of Changes:

#### File: `app/Http/Controllers/Api/UsersController.php`
**Before:**
```php
if ($key !== 'password' && $user->{$key} != $value) {
```

**After:**
```php
if ($key !== 'password' && $user->{$key} !== $value) {
```

#### File: `app/Services/VoiceRoutingManager.php`
**Before:**
```php
if ($typeValue == 'user') {
```

**After:**
```php
if ($typeValue === 'user') {
```

## Verification Steps

1. **Static Analysis:**
   ```bash
   # If PHPStan configured
   ./vendor/bin/phpstan analyse
   ```

2. **Test Suite:**
   ```bash
   php artisan test
   ```

3. **Type Safety Check:**
   - Verify no type coercion issues
   - Check for strict comparison benefits

## Rollback Plan

If strict comparisons break logic:
1. Identify specific breaking cases
2. Use intentional loose comparison with comments
3. Document why loose comparison needed

## Testing Requirements

- [ ] All tests pass
- [ ] No type coercion bugs
- [ ] Static analysis clean
- [ ] Functionality preserved

## Documentation Updates

- Document comparison standards
- Update coding guidelines
- Mark as completed in master work plan

## Completion Criteria

- [ ] All loose comparisons replaced
- [ ] Static analysis configured
- [ ] Tests pass
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #17: Incomplete PHPDoc Documentation

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 4-6 hours
**Assigned:** Unassigned

## Problem Description

Some methods lack proper PHPDoc blocks or have incomplete documentation.

**Location:** Various model methods and services

## Impact Assessment

- **Severity:** Normal - Developer experience
- **Scope:** Code documentation
- **Risk:** Low - Affects maintainability
- **Dependencies:** Development workflow

## Solution Overview

Add comprehensive PHPDoc documentation to all public methods following Laravel/PHP standards.

## Implementation Steps

### Phase 1: Documentation Audit (1 hour)
1. Identify undocumented methods
2. Review existing documentation quality
3. Establish documentation standards

### Phase 2: Document Models (2 hours)
1. Add PHPDoc to all model methods
2. Document relationships and scopes
3. Add usage examples

### Phase 3: Document Services (1-2 hours)
1. Document service classes
2. Add method documentation
3. Include parameter/return types

### Phase 4: Documentation Tools (30 minutes)
1. Configure documentation generation
2. Add to CI/CD pipeline
3. Create documentation standards

## Code Changes

### Example Documentation:

#### File: `app/Models/User.php`
**Before:**
```php
public function scopeSearch($query, string $search)
```

**After:**
```php
/**
 * Scope a query to search users by name or email.
 *
 * @param \Illuminate\Database\Eloquent\Builder $query
 * @param string $search The search term to filter by
 * @return \Illuminate\Database\Eloquent\Builder
 *
 * @example
 * User::search('john')->get();
 */
public function scopeSearch($query, string $search): Builder
```

#### File: `app/Services/VoiceRoutingManager.php`
```php
/**
 * Route an inbound call based on DID configuration and business rules.
 *
 * @param array $webhookData Cloudonix webhook payload
 * @return \App\Services\CxmlResponse
 * @throws \App\Exceptions\RoutingException
 *
 * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect
 */
public function routeInboundCall(array $webhookData): CxmlResponse
```

## Verification Steps

1. **Documentation Generation:**
   ```bash
   # If configured
   php artisan ide-helper:generate
   ```

2. **PHPDoc Validation:**
   ```bash
   # Use PHPStan or similar
   ./vendor/bin/phpstan analyse --level=1
   ```

3. **IDE Support:**
   - Verify autocomplete works
   - Check parameter hints
   - Test refactoring support

## Rollback Plan

If documentation becomes outdated:
1. Make documentation optional for now
2. Add TODO comments for future documentation
3. Implement gradual documentation approach

## Testing Requirements

- [ ] All public methods documented
- [ ] PHPDoc standards followed
- [ ] Documentation generation works
- [ ] IDE support improved

## Documentation Updates

- Create documentation standards guide
- Add to contribution guidelines
- Mark as completed in master work plan

## Completion Criteria

- [ ] Comprehensive PHPDoc added
- [ ] Standards documented
- [ ] Tools configured
- [ ] Code reviewed and approved

---

**Estimated Completion:** 4-6 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #18: Missing Linting Configuration

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 1-2 hours
**Assigned:** Unassigned

## Problem Description

No ESLint configuration despite being in package.json, leading to inconsistent code style.

**Location:** `frontend/` directory

## Impact Assessment

- **Severity:** Important - Code quality enforcement
- **Scope:** Frontend development
- **Risk:** Medium - Inconsistent code quality
- **Dependencies:** Node.js tooling

## Solution Overview

Set up ESLint with React/TypeScript rules and integrate into development workflow.

## Implementation Steps

### Step 1: Install Dependencies (15 minutes)
1. Install ESLint and plugins
2. Add Prettier for formatting
3. Configure for React/TypeScript

### Step 2: Create Configuration (30 minutes)
1. Set up `.eslintrc.js`
2. Configure rules for React/TypeScript
3. Add import/export rules

### Step 3: Integrate with Build Process (30 minutes)
1. Add lint scripts to package.json
2. Configure pre-commit hooks
3. Set up CI/CD integration

### Step 4: Initial Lint Run (30 minutes)
1. Run linter on existing code
2. Fix critical issues
3. Configure ignore rules

## Code Changes

### New File: `frontend/.eslintrc.js`
```javascript
module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    '@typescript-eslint/recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.js'],
  parser: '@typescript-eslint/parser',
  plugins: ['react-refresh'],
  rules: {
    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],
  },
}
```

### File: `frontend/package.json`
```json
{
  "scripts": {
    "lint": "eslint . --ext ts,tsx --report-unused-disable-directives --max-warnings 0",
    "lint:fix": "eslint . --ext ts,tsx --fix",
    "format": "prettier --write ."
  }
}
```

### New File: `frontend/.prettierrc`
```json
{
  "semi": true,
  "trailingComma": "es5",
  "singleQuote": true,
  "printWidth": 80,
  "tabWidth": 2,
  "useTabs": false
}
```

## Verification Steps

1. **Lint Check:**
   ```bash
   cd frontend
   npm run lint
   ```

2. **Auto-fix Test:**
   ```bash
   npm run lint:fix
   ```

3. **Format Check:**
   ```bash
   npm run format
   ```

4. **CI/CD Integration:**
   - Verify linting in pipeline
   - Check pre-commit hooks

## Rollback Plan

If linting is too strict:
1. Reduce rule severity levels
2. Add more ignore patterns
3. Make linting warnings-only initially

## Testing Requirements

- [ ] ESLint configuration working
- [ ] Prettier formatting applied
- [ ] Pre-commit hooks active
- [ ] CI/CD integration working

## Documentation Updates

- Update development setup guide
- Document linting standards
- Mark as completed in master work plan

## Completion Criteria

- [ ] ESLint configured and working
- [ ] Prettier integrated
- [ ] Development workflow updated
- [ ] Code reviewed and approved

---

**Estimated Completion:** 1-2 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #19: Type Definition Inconsistencies

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

Different User types in `types/index.ts` vs `types/api.types.ts` with conflicting role definitions.

**Location:** `frontend/src/types/index.ts`, `frontend/src/types/api.types.ts`

## Impact Assessment

- **Severity:** Important - Type safety violations
- **Scope:** Frontend type system
- **Risk:** Medium - Runtime errors from type mismatches
- **Dependencies:** TypeScript, API integration

## Solution Overview

Consolidate type definitions into single source of truth and align with backend models.

## Implementation Steps

### Phase 1: Type Audit (30 minutes)
1. Compare existing type definitions
2. Identify conflicts and inconsistencies
3. Map to backend models

### Phase 2: Consolidate Types (1 hour)
1. Create unified type definitions
2. Remove duplicate/conflicting types
3. Align with backend API responses

### Phase 3: Update Usage (45 minutes)
1. Update all imports to use consolidated types
2. Fix type mismatches throughout codebase
3. Add proper type guards where needed

### Phase 4: Type Safety Verification (30 minutes)
1. Run TypeScript compilation
2. Verify no type errors
3. Test with API responses

## Code Changes

### New File: `frontend/src/types/index.ts` (Consolidated)
```typescript
export interface User {
  id: string
  name: string
  email: string
  role: UserRole
  status: UserStatus
  organization_id: string
  created_at: string
  updated_at: string
}

export enum UserRole {
  OWNER = 'pbx_owner',
  ADMIN = 'pbx_admin',
  USER = 'pbx_user',
  REPORTER = 'reporter'
}

export enum UserStatus {
  ACTIVE = 'active',
  SUSPENDED = 'suspended',
  DELETED = 'deleted'
}

// API Response types
export interface ApiResponse<T> {
  data: T
  message?: string
  errors?: Record<string, string[]>
}

export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
```

### File: `frontend/src/types/api.types.ts` (Update or Remove)
- Either remove this file if types are consolidated
- Or keep only API-specific types (not domain models)

## Verification Steps

1. **TypeScript Compilation:**
   ```bash
   cd frontend
   npx tsc --noEmit
   ```

2. **Type Usage Check:**
   ```bash
   # Search for inconsistent imports
   grep -r "from '@/types'" src/
   ```

3. **API Integration Test:**
   - Test API calls with proper types
   - Verify response typing works

## Rollback Plan

If consolidation breaks functionality:
1. Keep separate type files temporarily
2. Gradually migrate to consolidated types
3. Use type unions for backward compatibility

## Testing Requirements

- [ ] TypeScript compilation succeeds
- [ ] No type conflicts
- [ ] API integration working
- [ ] Type safety improved

## Documentation Updates

- Document type organization
- Update API integration guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Type definitions consolidated
- [ ] No conflicts or inconsistencies
- [ ] TypeScript compilation clean
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #20: Poor Component Architecture

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 6-8 hours
**Assigned:** Unassigned

## Problem Description

Components handle validation, API calls, UI state, and rendering, violating separation of concerns.

**Location:** Various React components

## Impact Assessment

- **Severity:** Normal - Architecture issue
- **Scope:** Frontend components
- **Risk:** Medium - Difficult to test and maintain
- **Dependencies:** React patterns, testing framework

## Solution Overview

Extract business logic to custom hooks and implement container/presentational pattern.

## Implementation Steps

### Phase 1: Architecture Analysis (1 hour)
1. Identify components with mixed concerns
2. Map data flow and state management
3. Plan hook extraction strategy

### Phase 2: Create Custom Hooks (3-4 hours)
1. Extract data fetching logic
2. Extract form state management
3. Extract business logic
4. Create reusable hooks

### Phase 3: Refactor Components (2-3 hours)
1. Split components into container/presentational
2. Update prop interfaces
3. Implement proper separation

### Phase 4: Testing Setup (30 minutes)
1. Prepare for component testing
2. Add hook testing
3. Verify separation works

## Code Changes

### New File: `frontend/src/hooks/useUsers.ts`
```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/services/api'

export function useUsers(filters: UserFilters) {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => api.getUsers(filters),
  })
}

export function useCreateUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: api.createUser,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
  })
}
```

### New File: `frontend/src/hooks/useUserForm.ts`
```typescript
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'

const userSchema = z.object({
  name: z.string().min(1),
  email: z.string().email(),
  role: z.nativeEnum(UserRole),
})

export function useUserForm(defaultValues?: Partial<User>) {
  return useForm<User>({
    resolver: zodResolver(userSchema),
    defaultValues,
  })
}
```

### Component Refactoring Example:

#### Before: Mixed concerns in one component
```typescript
function UserForm({ onSubmit, onCancel }) {
  const [loading, setLoading] = useState(false)
  const [formData, setFormData] = useState({})
  
  // Form logic, API calls, validation, UI all mixed
}
```

#### After: Separated concerns
```typescript
function UserForm({ onSubmit, onCancel }) {
  const form = useUserForm()
  const createUser = useCreateUser()
  
  // Only UI concerns
}

function UserFormContainer() {
  const createUser = useCreateUser()
  
  // Business logic orchestration
}
```

## Verification Steps

1. **Component Separation:**
   - Verify hooks contain only logic
   - Confirm components handle only UI
   - Check prop interfaces are clean

2. **Testing Preparation:**
   ```bash
   # Once testing is set up
   npm run test -- --testPathPattern=useUsers
   ```

3. **Functionality Preservation:**
   - Test all user operations work
   - Verify form validation
   - Check error handling

## Rollback Plan

If refactoring is too complex:
1. Start with hook extraction only
2. Keep component structure initially
3. Gradually implement separation

## Testing Requirements

- [ ] Business logic extracted to hooks
- [ ] Components focused on UI
- [ ] Custom hooks testable
- [ ] Functionality preserved

## Documentation Updates

- Document component architecture patterns
- Update React best practices guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Separation of concerns implemented
- [ ] Custom hooks created
- [ ] Component architecture improved
- [ ] Code reviewed and approved

---

**Estimated Completion:** 6-8 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #21: Missing Error Boundaries

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

No error boundaries to catch JavaScript errors, causing entire app crashes.

**Location:** React component tree

## Impact Assessment

- **Severity:** Normal - Error handling
- **Scope:** Frontend error management
- **Risk:** Medium - Poor user experience
- **Dependencies:** React error handling

## Solution Overview

Implement error boundaries at key component levels with fallback UI and error reporting.

## Implementation Steps

### Phase 1: Error Boundary Component (1 hour)
1. Create reusable ErrorBoundary component
2. Add error reporting integration
3. Implement fallback UI

### Phase 2: Strategic Placement (45 minutes)
1. Add to route-level components
2. Wrap critical sections
3. Add to async operations

### Phase 3: Error Reporting (30 minutes)
1. Integrate error tracking service
2. Add error context logging
3. Configure production vs development behavior

### Phase 4: Testing (30 minutes)
1. Test error boundary behavior
2. Verify error reporting
3. Check fallback UI

## Code Changes

### New File: `frontend/src/components/ErrorBoundary.tsx`
```typescript
import React from 'react'
import { toast } from 'sonner'

interface ErrorBoundaryState {
  hasError: boolean
  error?: Error
}

interface ErrorBoundaryProps {
  children: React.ReactNode
  fallback?: React.ComponentType<{ error?: Error; reset: () => void }>
}

export class ErrorBoundary extends React.Component<
  ErrorBoundaryProps,
  ErrorBoundaryState
> {
  constructor(props: ErrorBoundaryProps) {
    super(props)
    this.state = { hasError: false }
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, errorInfo: React.ErrorInfo) {
    // Log error to service
    console.error('Error caught by boundary:', error, errorInfo)
    
    // Report to error tracking service
    if (process.env.NODE_ENV === 'production') {
      // reportError(error, errorInfo)
    }
    
    toast.error('Something went wrong. Please try again.')
  }

  reset = () => {
    this.setState({ hasError: false, error: undefined })
  }

  render() {
    if (this.state.hasError) {
      const FallbackComponent = this.props.fallback || DefaultFallback
      return <FallbackComponent error={this.state.error} reset={this.reset} />
    }

    return this.props.children
  }
}

function DefaultFallback({ error, reset }: { error?: Error; reset: () => void }) {
  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="text-center">
        <h2 className="text-2xl font-bold text-red-600 mb-4">
          Oops! Something went wrong
        </h2>
        <p className="text-gray-600 mb-4">
          We're sorry, but something unexpected happened.
        </p>
        <button
          onClick={reset}
          className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
        >
          Try Again
        </button>
      </div>
    </div>
  )
}
```

### File: `frontend/src/App.tsx` (or main component)
```typescript
import { ErrorBoundary } from '@/components/ErrorBoundary'

function App() {
  return (
    <ErrorBoundary>
      {/* App content */}
    </ErrorBoundary>
  )
}
```

## Verification Steps

1. **Error Simulation:**
   - Trigger JavaScript errors
   - Verify boundary catches them
   - Check fallback UI displays

2. **Error Reporting:**
   - Verify errors are logged
   - Check production error reporting
   - Test error context capture

3. **Recovery:**
   - Test reset functionality
   - Verify app continues working after error

## Rollback Plan

If error boundaries interfere:
1. Make boundaries optional initially
2. Add feature flags for error handling
3. Implement gradual rollout

## Testing Requirements

- [ ] Error boundaries catch errors
- [ ] Fallback UI displays correctly
- [ ] Error reporting works
- [ ] Recovery functionality works

## Documentation Updates

- Document error handling patterns
- Update troubleshooting guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Error boundaries implemented
- [ ] Fallback UI created
- [ ] Error reporting configured
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________