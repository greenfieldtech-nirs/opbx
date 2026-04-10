# Code Review Workplan for OpBX

**Version**: 1.0  
**Last Updated**: 2026-04-09  
**Applies To**: OpBX PBX Platform (Laravel 12 + React 18 + Go Dialer Worker)

---

## Purpose

This document is a **repeatable workplan** for conducting comprehensive code reviews on the OpBX codebase. When a code review is conducted, the reviewer follows this workplan and produces a **to-do document** saved at `/docs/review-workplan/code-review-{date}/` where `{date}` is the date of the review.

The to-do document lists all required tasks to bring the source code to the desired quality level, organized by severity and category.

---

## Pre-Review Preparation

### 1. Context Gathering

Before reviewing code, understand:

1. **What code changes were made and why** — Review the PR description, commit messages, and linked issues
2. **What specific areas the developer wants feedback on** — Check PR comments or request clarification
3. **Relevant coding standards** — Reference `AGENTS.md` and this workplan
4. **Related issues, PRs, or architectural decisions** — Search for context in GitHub/GitLab
5. **Team conventions and review priorities** — Align with current sprint goals

### 2. Environment Setup

```bash
# Verify code quality tools are available
vendor/bin/pint --version
cd frontend && npm run lint --version
cd ../dialer-worker && go version

# Run baseline checks before review
vendor/bin/pint --test
cd frontend && npm run lint && npm run type-check
```

### 3. Review Scope Definition

Determine which review areas apply to the changes:
- Full review: All 9 areas
- Focused review: Areas 1, 2, 3 based on changed files
- Security review: Areas 1 (security subset), 5, 6

---

## Review Execution Order

Reviews MUST be conducted in this priority order to catch critical issues first:

### Phase 1: Critical Security & Correctness (Stop-the-Presses)
- Area 1: PHP Backend — Security vulnerabilities, tenant isolation
- Area 5: Database — Data integrity, scope coverage
- Area 6: API Design — Authorization bypass risks

### Phase 2: High-Priority Issues
- Area 1: PHP Backend — N+1 queries, transaction correctness
- Area 2: Frontend — Type safety, error handling
- Area 4: Architecture — Pattern violations, race conditions

### Phase 3: Quality & Maintainability
- Area 3: Go Dialer Worker
- Area 7: Test Coverage
- Area 8: Documentation
- Area 9: Infrastructure

---

## Review Area 1: PHP Backend Code Quality

### 1.1 PSR-12 Compliance & Strict Types

**What to Review:**
- All PHP files in `app/` directory
- Focus on new/modified files in PR

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| `declare(strict_types=1)` | Present at line 1 of every PHP file | **Critical** |
| Indentation | 4 spaces, no tabs | Medium |
| Line endings | LF (Unix-style) | Low |
| Import order | Built-in → Laravel → App namespace | Medium |
| Unused imports | No unused `use` statements | Low |
| File encoding | UTF-8 without BOM | Low |

**Files to Sample:**
- All new controllers
- All new service classes
- All new models
- Any modified enums

**Output Template Entry:**
```markdown
### [CR-{n}] Missing strict_types declaration
- **Severity:** Critical
- **Category:** PHP Backend — PSR-12 Compliance
- **File(s):** `app/Http/Controllers/ExampleController.php`
- **Lines:** 1
- **Finding:** File lacks `declare(strict_types=1)` declaration
- **Standard:** AGENTS.md — "declare(strict_types=1) at top of every file"
- **Recommendation:** Add `<?php\ndeclare(strict_types=1);` at file start
- **Effort:** S
```

---

### 1.2 Controller Structure

**What to Review:**
- All controllers in `app/Http/Controllers/`
- CRUD controllers extending `AbstractApiCrudController`
- Non-CRUD controllers (IVR, Webhooks, VoiceRouting)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Controller thinness | <50 lines per action, delegates to services | Medium |
| AbstractApiCrudController hooks | Proper use of `beforeStore()`, `beforeUpdate()`, `afterDestroy()` | Medium |
| Duplicate logic | No code duplication across controllers | High |
| Legacy files | Identify deprecated controllers (e.g., `ExtensionController.php` vs `ExtensionCrudController.php`) | Medium |
| Constructor injection | Dependencies injected, not resolved via `app()` | Medium |
| Method visibility | `public` for actions, `protected` for hooks | Low |

**Anti-Patterns to Flag:**
- Business logic in controllers (should be in services)
- Direct DB queries in controllers (use repositories/services)
- Hardcoded values (use config or enums)
- Missing authorization checks

**Files to Review:**
- `app/Http/Controllers/ExtensionCrudController.php`
- `app/Http/Controllers/IvrMenuController.php` (note: does NOT extend AbstractApiCrudController)
- `app/Http/Controllers/AutoDialerCampaignController.php` (788 lines)
- `app/Http/Controllers/DialerWorkerController.php` (1007 lines)

**Output Template Entry:**
```markdown
### [CR-{n}] Business logic in controller
- **Severity:** High
- **Category:** PHP Backend — Controller Structure
- **File(s):** `app/Http/Controllers/XController.php`
- **Lines:** 45-89
- **Finding:** Complex validation logic duplicated in controller instead of using FormRequest
- **Standard:** AGENTS.md — "Controllers: thin logic, delegate to services"
- **Recommendation:** Extract to `StoreXRequest` FormRequest class
- **Effort:** M
```

---

### 1.3 Service Class Responsibility

**What to Review:**
- All classes in `app/Services/` and subdirectories
- Managers (e.g., `VoiceRoutingManager` — 2,136 lines)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Single Responsibility | One primary purpose per service | High |
| Class size | <500 lines preferred, <1000 lines max | Medium |
| Method size | <50 lines per method | Medium |
| Cyclomatic complexity | <10 per method | High |
| Dependency injection | Constructor injection preferred | Medium |
| Static methods | Minimize use, prefer instance methods | Low |
| Return type declarations | All methods have return types | Medium |

**Services Requiring Special Attention:**
| Service | Lines | Concern |
|---------|-------|---------|
| `VoiceRoutingManager` | 2,136 | Too large, needs decomposition |
| `CloudonixClient` | 1,509 | API client complexity |
| `CxmlBuilder` | 714 | CXML generation correctness |
| `AutoDialerCampaignController` | 788 | Controller/service boundary |
| `DialerWorkerController` | 1,007 | Worker communication logic |
| `BusinessHoursSchedule` | 408 | Schedule calculation complexity |
| `AlbsDistributionService` | 372 | Load balancer logic |
| `RecordingUploadService` | 381 | File handling security |

**Output Template Entry:**
```markdown
### [CR-{n}] Service violates Single Responsibility
- **Severity:** High
- **Category:** PHP Backend — Service Design
- **File(s):** `app/Services/VoiceRouting/VoiceRoutingManager.php`
- **Lines:** 1-2136
- **Finding:** Class handles routing decisions, CXML generation, caching, and strategy selection
- **Standard:** SOLID — Single Responsibility Principle
- **Recommendation:** Extract CXML generation to CxmlBuilder, extract caching to VoiceRoutingCacheService
- **Effort:** L
```

---

### 1.4 Model Quality

**What to Review:**
- All models in `app/Models/`
- Focus on complex models: `Extension` (309 lines), `DidNumber` (358 lines)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Tenant scoping | `#[ScopedBy([OrganizationScope::class])]` present | **Critical** |
| Fillable/guarded | Proper mass assignment protection | High |
| Relationships | Type-hinted return types, proper inverse | Medium |
| Casts | All date/json/enum fields cast | Medium |
| Scopes | Reusable query scopes for common filters | Low |
| Accessors/Mutators | Used appropriately, not overused | Low |
| Boot methods | Proper parent::boot() calls | Medium |
| Soft deletes | Consistent with migration | Medium |

**Critical Security Check:**
```php
// EVERY model MUST have this attribute
#[ScopedBy([OrganizationScope::class])]
class ExampleModel extends Model
{
    // ...
}
```

**Models to Review:**
- `app/Models/Extension.php` — Complex routing logic
- `app/Models/DidNumber.php` — Polymorphic routing config
- `app/Models/User.php` — Authentication, roles
- `app/Models/Organization.php` — Tenant root
- `app/Models/AutoDialerCampaign.php` — Campaign state

**Output Template Entry:**
```markdown
### [CR-{n}] Missing tenant scope on model
- **Severity:** Critical
- **Category:** PHP Backend — Model Security
- **File(s):** `app/Models/ExampleModel.php`
- **Lines:** 1-50
- **Finding:** Model lacks `#[ScopedBy([OrganizationScope::class])]` attribute
- **Standard:** AGENTS.md — "All models scoped by organization_id via OrganizationScope"
- **Recommendation:** Add `#[ScopedBy([OrganizationScope::class])]` class attribute
- **Effort:** S
```

---

### 1.5 Enum Usage Consistency

**What to Review:**
- All enums in `app/Enums/` (26 total)
- Focus on `ExtensionType` (111 lines)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Backing type | All enums backed by `string` | Medium |
| Case naming | `UPPER_SNAKE_CASE` | Medium |
| Class naming | `PascalCase` | Low |
| Value consistency | Meaningful, consistent values | Low |
| Usage in models | Proper casting in `$casts` | Medium |

**Enum Checklist:**
- [ ] `ExtensionType` — 111 lines, verify all extension types covered
- [ ] `UserRole` — Role hierarchy correct
- [ ] `DestinationType` — Routing destination types
- [ ] `CallStatus` — Call state machine values
- [ ] `CampaignStatus` — Dialer campaign states

**Output Template Entry:**
```markdown
### [CR-{n}] Enum not backed by string
- **Severity:** Medium
- **Category:** PHP Backend — Enum Consistency
- **File(s):** `app/Enums/ExampleEnum.php`
- **Lines:** 5-15
- **Finding:** Enum lacks string backing, stored as integer in database
- **Standard:** AGENTS.md — "Enums: backed by string values"
- **Recommendation:** Change to `enum ExampleEnum: string`
- **Effort:** M (requires migration)
```

---

### 1.6 FormRequest Validation

**What to Review:**
- All classes in `app/Http/Requests/` (52 total)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Authorization | `authorize()` method implemented | High |
| Validation rules | Complete rule coverage | High |
| Custom messages | User-friendly error messages | Low |
| Rule reuse | Custom rules for complex validation | Medium |
| Data preparation | `prepareForValidation()` if needed | Low |
| Type hints | Return type on `rules()` | Low |

**Critical Validation Patterns:**
```php
// Tenant isolation in authorization
public function authorize(): bool
{
    return $this->user()->can('update', $this->route('extension'));
}

// Complete validation rules
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'type' => ['required', new Enum(ExtensionType::class)],
        'organization_id' => ['required', 'exists:organizations,id'],
    ];
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] Missing authorization in FormRequest
- **Severity:** High
- **Category:** PHP Backend — Validation
- **File(s):** `app/Http/Requests/StoreExtensionRequest.php`
- **Lines:** 12-18
- **Finding:** `authorize()` returns true without checking user permissions
- **Standard:** Laravel — FormRequest should validate authorization
- **Recommendation:** Implement proper authorization check using policies
- **Effort:** M
```

---

### 1.7 Resource Classes

**What to Review:**
- All classes in `app/Http/Resources/`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Data transformation | Consistent field naming | Medium |
| Conditional fields | `when()` for optional data | Low |
| Relationship loading | Eager loaded before transformation | High |
| Type consistency | Same field types across resources | Medium |
| Pagination | Consistent pagination metadata | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] N+1 query in Resource
- **Severity:** High
- **Category:** PHP Backend — Resource Performance
- **File(s):** `app/Http/Resources/ExtensionResource.php`
- **Lines:** 25-30
- **Finding:** Resource accesses `$this->organization->name` without eager loading
- **Standard:** Laravel Best Practices — Eager load relationships in controller
- **Recommendation:** Add `->with('organization')` in controller query
- **Effort:** S
```

---

### 1.8 Policy Authorization Coverage

**What to Review:**
- All policies in `app/Policies/` (17 total)
- Controller authorization calls

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Policy existence | Every model has a policy | High |
| Tenant scoping | `organization_id` check in policies | **Critical** |
| Method coverage | All controller actions covered | High |
| Super admin bypass | Platform admins can access | Medium |
| Authorization calls | `$this->authorize()` in controllers | High |

**Critical Pattern:**
```php
public function update(User $user, Extension $extension): bool
{
    return $user->organization_id === $extension->organization_id
        && $user->can('extensions.update');
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] Missing tenant check in policy
- **Severity:** Critical
- **Category:** PHP Backend — Authorization
- **File(s):** `app/Policies/ExtensionPolicy.php`
- **Lines:** 18-22
- **Finding:** Policy checks permission but not organization ownership
- **Standard:** Multi-tenancy — Must verify resource belongs to user's organization
- **Recommendation:** Add `&& $user->organization_id === $extension->organization_id`
- **Effort:** S
```

---

### 1.9 Error Handling Patterns

**What to Review:**
- Try/catch blocks in services and controllers
- Exception rendering in `app/Exceptions/Handler.php`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Specific exceptions | Catch specific exceptions, not `Exception` | Medium |
| Structured responses | JSON error format consistent | High |
| Context logging | `call_id` in webhook logs | High |
| Silent failures | No empty catch blocks | **Critical** |
| Transaction rollback | DB exceptions trigger rollback | High |

**Anti-Patterns:**
```php
// NEVER do this
try {
    // operation
} catch (\Exception $e) {
    // silent failure
}

// DO this
try {
    // operation
} catch (CloudonixApiException $e) {
    Log::error('API call failed', ['call_id' => $callId, 'error' => $e->getMessage()]);
    throw new HttpException(502, 'Voice service unavailable');
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] Silent failure in catch block
- **Severity:** Critical
- **Category:** PHP Backend — Error Handling
- **File(s):** `app/Services/ExampleService.php`
- **Lines:** 45-48
- **Finding:** Empty catch block swallows all exceptions
- **Standard:** Error handling — Never silently fail
- **Recommendation:** Log error with context and re-throw or return error response
- **Effort:** S
```

---

### 1.10 N+1 Query Detection

**What to Review:**
- All controller index/show methods
- Resource classes with relationships
- Service methods with loops

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Eager loading | `with()` for all relationships | High |
| Cursor pagination | For large datasets | Medium |
| Query count | Monitor with Debugbar or logs | High |
| Nested relationships | `with(['a.b'])` for deep loading | Medium |

**Detection Method:**
```bash
# Enable query log in local testing
DB::enableQueryLog();
// run code
$queries = DB::getQueryLog();
```

**Output Template Entry:**
```markdown
### [CR-{n}] N+1 query in index method
- **Severity:** High
- **Category:** PHP Backend — Performance
- **File(s):** `app/Http/Controllers/ExtensionCrudController.php`
- **Lines:** 25-35
- **Finding:** Loading extensions without eager loading `organization` relationship
- **Standard:** Performance — Eager load to prevent N+1
- **Recommendation:** Change `Extension::paginate()` to `Extension::with('organization')->paginate()`
- **Effort:** S
```

---

### 1.11 Database Transaction Usage

**What to Review:**
- Multi-step operations in services
- Financial/state-critical operations

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Transaction boundaries | `DB::transaction()` for multi-step ops | High |
| Nested transactions | Proper savepoint handling | Medium |
| Exception handling | Rollback on failure | High |
| Lock usage | `lockForUpdate()` where needed | High |

**Critical Pattern:**
```php
DB::transaction(function () use ($data) {
    $campaign = Campaign::create($data);
    $campaign->contacts()->createMany($contacts);
    $campaign->increment('contact_count', count($contacts));
});
```

**Output Template Entry:**
```markdown
### [CR-{n}] Missing transaction for multi-step operation
- **Severity:** High
- **Category:** PHP Backend — Data Integrity
- **File(s):** `app/Services/CampaignService.php`
- **Lines:** 55-70
- **Finding:** Creates campaign, then contacts, then updates count — no transaction
- **Standard:** Data integrity — Multi-step operations must be atomic
- **Recommendation:** Wrap in `DB::transaction()`
- **Effort:** S
```

---

### 1.12 Code Duplication

**What to Review:**
- Cross-controller patterns
- Service method similarities
- Validation rule duplication

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| DRY violations | >3 similar code blocks | Medium |
| Extract opportunities | Traits, base classes, helper methods | Medium |
| Copy-paste errors | Slight variations that should be identical | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Code duplication across controllers
- **Severity:** Medium
- **Category:** PHP Backend — Maintainability
- **File(s):** `app/Http/Controllers/ExtensionCrudController.php`, `app/Http/Controllers/RingGroupController.php`
- **Lines:** Various
- **Finding:** Same tenant validation logic duplicated in 4 controllers
- **Standard:** DRY principle
- **Recommendation:** Extract to `ValidatesTenantAccess` trait
- **Effort:** M
```

---

### 1.13 Dead Code & Legacy Files

**What to Review:**
- File list for duplicates (e.g., `ExtensionController.php` vs `ExtensionCrudController.php`)
- Unused imports
- Deprecated methods

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Duplicate files | Only one controller per resource | Medium |
| Unused imports | No grayed-out `use` statements | Low |
| Deprecated methods | Marked with `@deprecated` | Low |
| Dead code | Methods never called | Medium |

**Known Legacy:**
- `ExtensionController.php` — appears alongside `ExtensionCrudController.php`
- `CallLogController.php` — marked DEPRECATED (130 lines)

**Output Template Entry:**
```markdown
### [CR-{n}] Legacy file still present
- **Severity:** Medium
- **Category:** PHP Backend — Cleanup
- **File(s):** `app/Http/Controllers/ExtensionController.php`
- **Lines:** N/A
- **Finding:** Legacy ExtensionController exists alongside ExtensionCrudController
- **Standard:** Code hygiene — Remove unused files
- **Recommendation:** Verify no routes reference it, then delete
- **Effort:** S
```

---

## Review Area 2: TypeScript/React Frontend Code Quality

### 2.1 Component Size & Decomposition

**What to Review:**
- All page components in `frontend/src/pages/` (33 total)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| File size | <500 lines preferred | Medium |
| Component size | <200 lines for reusable components | Medium |
| Single responsibility | One primary purpose per component | Medium |
| Extraction opportunities | Repeated JSX → sub-components | Low |

**Oversized Components to Review:**
| Component | Lines | Concern |
|-----------|-------|---------|
| `Extensions` | 2,231 | Needs decomposition |
| `BusinessHours` | 2,622 | Complex schedule UI |
| `RingGroups` | 1,730 | Multiple concerns |
| `IVRMenus` | 1,658 | Complex tree structure |
| `AutoDialerCampaigns` | Large | Campaign management |

**Output Template Entry:**
```markdown
### [CR-{n}] Component too large
- **Severity:** Medium
- **Category:** Frontend — Component Structure
- **File(s):** `frontend/src/pages/Extensions.tsx`
- **Lines:** 1-2231
- **Finding:** Single file contains list, form, table, and routing logic
- **Standard:** Maintainability — Components should be focused
- **Recommendation:** Extract into `ExtensionList`, `ExtensionForm`, `ExtensionTable` components
- **Effort:** L
```

---

### 2.2 TypeScript Type Safety

**What to Review:**
- All `.ts` and `.tsx` files
- Type definitions in `frontend/src/types/`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| `any` usage | Zero `any` types | High |
| Implicit any | All params/returns typed | High |
| Strict mode | Note: currently OFF | Medium |
| Type exports | Reusable types exported | Low |
| Interface vs Type | Consistent choice | Low |

**Configuration Note:**
```json
// tsconfig.json — strict mode is OFF
{
  "compilerOptions": {
    "strict": false  // This is intentional per AGENTS.md
  }
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] Excessive any usage
- **Severity:** High
- **Category:** Frontend — Type Safety
- **File(s):** `frontend/src/services/api.ts`
- **Lines:** 45, 67, 89
- **Finding:** Multiple `any` types used where specific interfaces should exist
- **Standard:** TypeScript best practices — Avoid any
- **Recommendation:** Define `ApiResponse<T>` and `ApiError` interfaces
- **Effort:** M
```

---

### 2.3 Hook Patterns

**What to Review:**
- Custom hooks in `frontend/src/hooks/`
- Hook usage in components

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Naming | `use` prefix for all hooks | Low |
| Dependency arrays | Complete and correct deps | **Critical** |
| Cleanup | Return cleanup functions for effects | High |
| Custom hook extraction | Repeated logic → custom hook | Medium |
| Hook rules | No hooks in loops/conditions | **Critical** |

**Critical Anti-Pattern:**
```tsx
// NEVER do this
if (condition) {
  useEffect(() => { ... }, []);  // Hook inside condition
}

// DO this
useEffect(() => {
  if (condition) {
    // logic here
  }
}, [condition]);
```

**Output Template Entry:**
```markdown
### [CR-{n}] Missing dependency in useEffect
- **Severity:** Critical
- **Category:** Frontend — Hook Patterns
- **File(s):** `frontend/src/pages/Extensions.tsx`
- **Lines:** 145-150
- **Finding:** `useEffect` depends on `organizationId` but it's not in dependency array
- **Standard:** React Hooks — Exhaustive dependencies required
- **Recommendation:** Add `organizationId` to dependency array or use eslint-disable with comment
- **Effort:** S
```

---

### 2.4 API Service Layer Consistency

**What to Review:**
- All files in `frontend/src/services/` (~19 files)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Base URL | Consistent API client | Medium |
| Error handling | Consistent error format | High |
| Type safety | Return types on all methods | Medium |
| Auth headers | Centralized token handling | Medium |
| Request/Response interceptors | Consistent transformation | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Inconsistent error handling in service
- **Severity:** High
- **Category:** Frontend — API Services
- **File(s):** `frontend/src/services/extensionService.ts`
- **Lines:** 30-45
- **Finding:** Some methods throw, others return `{error}` — inconsistent
- **Standard:** Consistency — All services should handle errors uniformly
- **Recommendation:** Use axios interceptors or consistent wrapper pattern
- **Effort:** M
```

---

### 2.5 Form Validation with Zod

**What to Review:**
- Zod schemas in form components
- Form validation error display

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Schema completeness | All fields validated | High |
| Error messages | User-friendly messages | Medium |
| Type inference | `z.infer<typeof schema>` used | Low |
| Refinement | Complex validations with `.refine()` | Medium |
| Default values | Proper defaults with `defaultValues` | Medium |

**Critical Pattern:**
```tsx
const schema = z.object({
  name: z.string().min(1, 'Name is required').max(255),
  extension: z.string().regex(/^\d+$/, 'Must be numeric'),
  type: z.nativeEnum(ExtensionType),
});

type FormData = z.infer<typeof schema>;
```

**Output Template Entry:**
```markdown
### [CR-{n}] Incomplete Zod schema
- **Severity:** High
- **Category:** Frontend — Form Validation
- **File(s):** `frontend/src/pages/Extensions.tsx`
- **Lines:** 89-105
- **Finding:** Schema missing validation for `routingConfig` nested object
- **Standard:** Validation completeness — All user input must be validated
- **Recommendation:** Add nested schema for routingConfig with proper type constraints
- **Effort:** M
```

---

### 2.6 State Management Patterns

**What to Review:**
- TanStack Query usage
- React Context usage
- Local state vs global state

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Server state | TanStack Query for API data | Medium |
| Client state | Context for global UI state | Medium |
| Cache invalidation | Proper query key structure | High |
| Optimistic updates | Where appropriate | Low |
| Loading states | Consistent loading UI | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Improper state management
- **Severity:** Medium
- **Category:** Frontend — State Management
- **File(s):** `frontend/src/pages/Extensions.tsx`
- **Lines:** 200-250
- **Finding:** Using useState for server data that should use TanStack Query
- **Standard:** State management — Server state belongs in TanStack Query
- **Recommendation:** Replace with `useQuery` hook and proper cache invalidation
- **Effort:** M
```

---

### 2.7 Error Boundary & Error Handling

**What to Review:**
- Error boundary implementation
- Error display components

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Error boundaries | At route level | High |
| Fallback UI | User-friendly error display | Medium |
| Error logging | Errors reported to monitoring | Medium |
| Recovery | Retry mechanisms where appropriate | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing error boundary
- **Severity:** High
- **Category:** Frontend — Error Handling
- **File(s):** `frontend/src/App.tsx`
- **Lines:** N/A
- **Finding:** No error boundary for route-level error catching
- **Standard:** Error resilience — Unhandled errors should not crash app
- **Recommendation:** Add ErrorBoundary wrapper around route components
- **Effort:** M
```

---

### 2.8 Empty State Pattern Compliance

**What to Review:**
- All list/table components

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Empty state | Displayed when no data | Medium |
| Call to action | Clear next step for user | Low |
| Consistency | Same pattern across pages | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing empty state
- **Severity:** Medium
- **Category:** Frontend — UX Patterns
- **File(s):** `frontend/src/pages/RingGroups.tsx`
- **Lines:** 150-200
- **Finding:** Table shows headers but no message when no ring groups exist
- **Standard:** UX — Empty states guide users
- **Recommendation:** Add EmptyState component with CTA to create first ring group
- **Effort:** S
```

---

### 2.9 Accessibility Basics

**What to Review:**
- Form inputs
- Interactive elements
- Color contrast (if visible)

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Labels | All inputs have labels | Medium |
| ARIA | Proper aria-* attributes | Medium |
| Keyboard nav | Focusable interactive elements | Medium |
| Color contrast | WCAG AA compliance | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing form labels
- **Severity:** Medium
- **Category:** Frontend — Accessibility
- **File(s):** `frontend/src/components/ExtensionForm.tsx`
- **Lines:** 45-50
- **Finding:** Input lacks associated label element
- **Standard:** WCAG — All inputs must have labels
- **Recommendation:** Add `<label htmlFor="extension">` or aria-label
- **Effort:** S
```

---

### 2.10 Performance Optimization

**What to Review:**
- Large lists
- Expensive computations
- Re-render patterns

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Memoization | `useMemo` for expensive calcs | Low |
| Callback stability | `useCallback` for props | Low |
| List virtualization | For >100 items | Medium |
| Image optimization | Lazy loading | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] List without virtualization
- **Severity:** Medium
- **Category:** Frontend — Performance
- **File(s):** `frontend/src/pages/CallLogs.tsx`
- **Lines:** 100-150
- **Finding:** Rendering all call logs without virtualization (could be 1000s)
- **Standard:** Performance — Virtualize long lists
- **Recommendation:** Implement react-window or react-virtualized
- **Effort:** M
```

---

## Review Area 3: Go Dialer Worker Code Quality

### 3.1 Error Handling Patterns

**What to Review:**
- All `.go` files in `dialer-worker/`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Error checks | All errors checked | **Critical** |
| Error wrapping | `fmt.Errorf("...: %w", err)` | Medium |
| Fatal errors | `log.Fatal` only for unrecoverable | High |
| Error propagation | Errors returned, not logged and dropped | **Critical** |

**Output Template Entry:**
```markdown
### [CR-{n}] Unchecked error
- **Severity:** Critical
- **Category:** Go — Error Handling
- **File(s):** `dialer-worker/executor.go`
- **Lines:** 45
- **Finding:** `file.Close()` error not checked
- **Standard:** Go best practices — Always check errors
- **Recommendation:** `if err := file.Close(); err != nil { log.Printf(...) }`
- **Effort:** S
```

---

### 3.2 Goroutine Safety

**What to Review:**
- Concurrent code in `executor.go`, `main.go`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| WaitGroups | Proper `sync.WaitGroup` usage | High |
| Channel closing | Safe channel close patterns | High |
| Context cancellation | Respect context cancellation | High |
| Race conditions | No shared state without sync | **Critical** |
| Resource cleanup | Deferred cleanup in goroutines | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Potential race condition
- **Severity:** Critical
- **Category:** Go — Concurrency
- **File(s):** `dialer-worker/executor.go`
- **Lines:** 80-95
- **Finding:** Shared counter incremented without mutex protection
- **Standard:** Go concurrency — Protect shared state
- **Recommendation:** Add `sync.Mutex` or use atomic operations
- **Effort:** M
```

---

### 3.3 Redis Client Usage

**What to Review:**
- Redis interactions in `cac.go` and other files

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Connection pooling | Proper client configuration | Medium |
| Key naming | Consistent key patterns | Low |
| TTL usage | Appropriate expiration | Medium |
| Error handling | Redis errors handled | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Redis connection not closed
- **Severity:** High
- **Category:** Go — Resource Management
- **File(s):** `dialer-worker/cac.go`
- **Lines:** 30-40
- **Finding:** Redis client created but not properly closed on shutdown
- **Standard:** Resource management — Clean up connections
- **Recommendation:** Add graceful shutdown with client.Close()
- **Effort:** S
```

---

### 3.4 HTTP Client Configuration

**What to Review:**
- HTTP client setup in all files

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| DisableKeepAlives | `DisableKeepAlives: true` set | **Critical** |
| Timeout | Reasonable timeout configured | High |
| Connection limits | Max connections limited | Medium |

**Critical Requirement:**
```go
// Required per AGENTS.md
http.Client{
    Transport: &http.Transport{
        DisableKeepAlives: true,  // Required for DNS freshness with nginx
    },
    Timeout: 30 * time.Second,
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] HTTP client missing DisableKeepAlives
- **Severity:** Critical
- **Category:** Go — HTTP Client
- **File(s):** `dialer-worker/main.go`
- **Lines:** 45-50
- **Finding:** HTTP client created without DisableKeepAlives: true
- **Standard:** AGENTS.md — Required for DNS freshness with nginx
- **Recommendation:** Add `DisableKeepAlives: true` to Transport
- **Effort:** S
```

---

### 3.5 Configuration Validation

**What to Review:**
- Config loading and validation
- Environment variable handling

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Required vars | All required vars checked | High |
| Default values | Sensible defaults | Low |
| Type validation | Proper type parsing | Medium |
| Validation errors | Clear error messages | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing config validation
- **Severity:** High
- **Category:** Go — Configuration
- **File(s):** `dialer-worker/main.go`
- **Lines:** 20-30
- **Finding:** REDIS_URL used without validation, empty string causes panic
- **Standard:** Configuration — Validate at startup
- **Recommendation:** Add validation with descriptive error message
- **Effort:** S
```

---

### 3.6 Logging Consistency

**What to Review:**
- Log statements across all files

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Structured logging | Consistent format | Low |
| Log levels | Appropriate level usage | Low |
| Context | Request/call IDs in logs | Medium |
| Sensitive data | No secrets in logs | **Critical** |

**Output Template Entry:**
```markdown
### [CR-{n}] Sensitive data in logs
- **Severity:** Critical
- **Category:** Go — Security
- **File(s):** `dialer-worker/executor.go`
- **Lines:** 67
- **Finding:** API token logged in error message
- **Standard:** Security — Never log credentials
- **Recommendation:** Redact sensitive fields before logging
- **Effort:** S
```

---

## Review Area 4: Architecture & Design Patterns

### 4.1 Control Plane vs Execution Plane Separation

**What to Review:**
- Controllers: CRUD vs VoiceRouting
- Services: Config vs Runtime

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Separation | Config changes don't affect runtime directly | High |
| CXML generation | Only in execution plane | High |
| Database writes | Control plane only for config | Medium |
| Cache usage | Runtime reads from cache | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Control plane leaking into execution plane
- **Severity:** High
- **Category:** Architecture — Plane Separation
- **File(s):** `app/Http/Controllers/VoiceRoutingController.php`
- **Lines:** 40-60
- **Finding:** Controller directly queries DB instead of using cached config
- **Standard:** Architecture — Execution plane should use cached runtime config
- **Recommendation:** Use VoiceRoutingCacheService for runtime lookups
- **Effort:** M
```

---

### 4.2 Strategy Pattern Implementation

**What to Review:**
- `app/Services/VoiceRouting/Strategies/` (8 strategy classes)
- `VoiceRoutingManager` strategy selection

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Interface compliance | All strategies implement same interface | High |
| Strategy selection | Clean selection logic | Medium |
| Context passing | Consistent context to strategies | Medium |
| New strategies | Easy to add new strategies | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Strategy pattern violation
- **Severity:** High
- **Category:** Architecture — Design Patterns
- **File(s):** `app/Services/VoiceRouting/VoiceRoutingManager.php`
- **Lines:** 150-200
- **Finding:** Strategy selection uses switch instead of registry/map
- **Standard:** Strategy pattern — Use registry for extensibility
- **Recommendation:** Implement StrategyRegistry with dependency injection
- **Effort:** M
```

---

### 4.3 AbstractApiCrudController Hook Consistency

**What to Review:**
- All controllers extending `AbstractApiCrudController`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Hook usage | Proper `beforeStore`, `afterDestroy`, etc. | Medium |
| Parent calls | `parent::` called when overriding | High |
| Consistency | Same hook patterns across controllers | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing parent call in hook
- **Severity:** High
- **Category:** Architecture — CRUD Patterns
- **File(s):** `app/Http/Controllers/ExtensionCrudController.php`
- **Lines:** 45-50
- **Finding:** `beforeStore()` override missing `parent::beforeStore()`
- **Standard:** Inheritance — Always call parent unless intentional
- **Recommendation:** Add parent call or document intentional omission
- **Effort:** S
```

---

### 4.4 Destination Routing System Consistency

**What to Review:**
- `ResourceReferenceChecker`
- Destination type enums
- Usage across modules

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Consistent types | Same destination types everywhere | High |
| Validation | All destinations validated | High |
| Circular refs | Prevention of circular routing | **Critical** |
| UI consistency | `DestinationSelector` used everywhere | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Destination routing inconsistency
- **Severity:** High
- **Category:** Architecture — Routing
- **File(s):** `app/Services/ResourceReferenceChecker.php`
- **Lines:** 80-120
- **Finding:** Some destination types not validated for circular references
- **Standard:** Routing safety — All destinations must be validated
- **Recommendation:** Add validation for missing destination types
- **Effort:** M
```

---

### 4.5 Cache-Aside Pattern Correctness

**What to Review:**
- `VoiceRoutingCacheService`
- Cache usage in voice routing

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Cache miss handling | Load from DB on miss | Medium |
| Cache invalidation | Proper invalidation on update | High |
| Stampede protection | Locking for cache rebuild | Medium |
| TTL | Appropriate expiration | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Cache invalidation missing
- **Severity:** High
- **Category:** Architecture — Caching
- **File(s):** `app/Services/VoiceRouting/VoiceRoutingCacheService.php`
- **Lines:** N/A
- **Finding:** Extension update doesn't invalidate routing cache
- **Standard:** Cache-aside — Updates must invalidate cache
- **Recommendation:** Add cache invalidation in Extension observer or controller
- **Effort:** M
```

---

### 4.6 Circuit Breaker Usage

**What to Review:**
- `CircuitBreaker` class usage
- External API calls

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Wrapped calls | External APIs use circuit breaker | High |
| State monitoring | Proper state transitions | Medium |
| Fallback behavior | Graceful degradation | High |
| Configuration | Appropriate thresholds | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] External API without circuit breaker
- **Severity:** High
- **Category:** Architecture — Resilience
- **File(s):** `app/Services/CloudonixClient.php`
- **Lines:** 200-250
- **Finding:** API call lacks circuit breaker protection
- **Standard:** Resilience — External calls must be protected
- **Recommendation:** Wrap Cloudonix API calls in CircuitBreaker
- **Effort:** M
```

---

### 4.7 Queue Job Design

**What to Review:**
- All job classes in `app/Jobs/`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Idempotency | Jobs safe to run multiple times | **Critical** |
| Retry configuration | Appropriate retry count | Medium |
| Timeout | Reasonable timeout set | Medium |
| Failed job handling | Proper failure logging | Medium |
| Serialization | Safe for queue transport | Medium |

**Critical Idempotency Pattern:**
```php
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    
    public int $tries = 3;
    public int $timeout = 60;
    
    public function handle(Redis $redis): void
    {
        // Check idempotency key
        if (!$redis->set("idem:webhook:{$this->eventId}", '1', 'EX', 3600, 'NX')) {
            return; // Already processed
        }
        
        // Process...
    }
}
```

**Output Template Entry:**
```markdown
### [CR-{n}] Queue job not idempotent
- **Severity:** Critical
- **Category:** Architecture — Queue Jobs
- **File(s):** `app/Jobs/ProcessCampaignCall.php`
- **Lines:** 25-50
- **Finding:** Job can create duplicate calls if retried
- **Standard:** Queue jobs — Must be idempotent for retries
- **Recommendation:** Add idempotency check using Redis or database unique constraint
- **Effort:** M
```

---

## Review Area 5: Database & Data Integrity

### 5.1 Migration Quality

**What to Review:**
- All migrations in `database/migrations/`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Reversibility | All migrations have `down()` | High |
| Index coverage | Foreign keys indexed | High |
| FK constraints | Proper foreign key definitions | Medium |
| Data types | Appropriate column types | Medium |
| Default values | Sensible defaults | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Migration missing down()
- **Severity:** High
- **Category:** Database — Migrations
- **File(s):** `database/migrations/2024_01_15_000000_create_example_table.php`
- **Lines:** 1-30
- **Finding:** Migration has up() but empty down() method
- **Standard:** Migrations — Must be reversible
- **Recommendation:** Implement down() to drop table
- **Effort:** S
```

---

### 5.2 Soft Delete Consistency

**What to Review:**
- Models with `SoftDeletes` trait
- Related migrations

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Trait usage | `SoftDeletes` where appropriate | Medium |
| Column exists | `deleted_at` in migration | High |
| Query scoping | `withTrashed()` used appropriately | Medium |
| Cascade behavior | Related records handled | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Soft delete without deleted_at column
- **Severity:** High
- **Category:** Database — Soft Deletes
- **File(s):** `app/Models/Extension.php`
- **Lines:** 15
- **Finding:** Model uses SoftDeletes but migration lacks deleted_at column
- **Standard:** Soft deletes — Column must exist
- **Recommendation:** Add deleted_at timestamp column to migration
- **Effort:** M (requires migration)
```

---

### 5.3 Organization Scope Coverage

**What to Review:**
- ALL models for tenant scoping

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Scope attribute | `#[ScopedBy([OrganizationScope::class])]` | **Critical** |
| FK column | `organization_id` exists | **Critical** |
| Index | `organization_id` indexed | High |

**Verification Script:**
```bash
# Check all models have OrganizationScope
grep -L "ScopedBy.*OrganizationScope" app/Models/*.php
```

**Output Template Entry:**
```markdown
### [CR-{n}] Model missing organization scope
- **Severity:** Critical
- **Category:** Database — Multi-Tenancy
- **File(s):** `app/Models/ExampleModel.php`
- **Lines:** 1-20
- **Finding:** Model lacks OrganizationScope, data isolation risk
- **Standard:** Multi-tenancy — All tenant models must be scoped
- **Recommendation:** Add `#[ScopedBy([OrganizationScope::class])]` attribute
- **Effort:** S
```

---

### 5.4 JSON Column Usage

**What to Review:**
- Models with JSON casts
- Validation of JSON data

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Casting | `$casts` includes 'json' or 'array' | Medium |
| Validation | JSON structure validated | High |
| Default value | Empty array/object default | Low |
| Querying | JSON columns queried efficiently | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] JSON column without validation
- **Severity:** High
- **Category:** Database — Data Integrity
- **File(s):** `app/Models/DidNumber.php`
- **Lines:** 25-30
- **Finding:** routing_config JSON column has no structure validation
- **Standard:** Data integrity — JSON must be validated
- **Recommendation:** Add FormRequest validation or model mutator validation
- **Effort:** M
```

---

### 5.5 Polymorphic Relationships

**What to Review:**
- `DidNumber` routing config
- Any other polymorphic patterns

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Type safety | Validated type values | High |
| Integrity | Referential integrity maintained | High |
| Performance | Indexed polymorphic columns | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Polymorphic relationship without validation
- **Severity:** High
- **Category:** Database — Relationships
- **File(s):** `app/Models/DidNumber.php`
- **Lines:** 40-50
- **Finding:** routable_type can be any string, no validation
- **Standard:** Data integrity — Polymorphic types must be validated
- **Recommendation:** Add validation against allowed types enum
- **Effort:** M
```

---

## Review Area 6: API Design Quality

### 6.1 REST Convention Adherence

**What to Review:**
- `routes/api.php`
- Controller action names

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Resource routes | Standard REST actions | Medium |
| HTTP methods | Correct method usage | High |
| Plural nouns | `/extensions` not `/extension` | Low |
| Nested resources | Proper nesting depth | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Non-RESTful route
- **Severity:** Medium
- **Category:** API Design — REST Conventions
- **File(s):** `routes/api.php`
- **Lines:** 45
- **Finding:** POST /extensions/{id}/activate instead of PATCH /extensions/{id}
- **Standard:** REST — Use standard actions
- **Recommendation:** Change to PATCH with status field update
- **Effort:** M
```

---

### 6.2 Response Format Consistency

**What to Review:**
- API Resource classes
- Error responses

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| JSON structure | Consistent envelope | High |
| Field naming | camelCase consistently | Medium |
| Null handling | Consistent null representation | Low |
| Date format | ISO 8601 consistently | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Inconsistent response format
- **Severity:** High
- **Category:** API Design — Response Format
- **File(s):** `app/Http/Resources/ExtensionResource.php`
- **Lines:** 20-30
- **Finding:** Some resources wrap in 'data' key, others don't
- **Standard:** API consistency — Uniform response structure
- **Recommendation:** Use base Resource class or API Resources consistently
- **Effort:** M
```

---

### 6.3 Pagination Patterns

**What to Review:**
- All index endpoints

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Pagination | All list endpoints paginated | Medium |
| Metadata | Standard pagination meta | Medium |
| Cursor support | Cursor for large datasets | Low |
| Max page size | Limit to prevent abuse | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing pagination
- **Severity:** Medium
- **Category:** API Design — Pagination
- **File(s):** `app/Http/Controllers/CallLogController.php`
- **Lines:** 25-30
- **Finding:** Index returns all records without pagination
- **Standard:** API design — Lists must be paginated
- **Recommendation:** Add `->paginate(50)` with max limit
- **Effort:** S
```

---

### 6.4 Filter/Sort Patterns

**What to Review:**
- Index endpoints with filtering

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Consistent params | Same filter names across endpoints | Low |
| Validation | Filter params validated | Medium |
| SQL injection | Safe query building | **Critical** |
| Sorting | Consistent sort param format | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] SQL injection in filter
- **Severity:** Critical
- **Category:** API Design — Security
- **File(s):** `app/Http/Controllers/ExtensionCrudController.php`
- **Lines:** 35-40
- **Finding:** User input used directly in whereRaw()
- **Standard:** Security — Never trust user input in SQL
- **Recommendation:** Use parameterized queries or query builder
- **Effort:** S
```

---

### 6.5 Error Response Structure

**What to Review:**
- Exception handler
- Validation error responses

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Structure | Consistent error format | High |
| HTTP status | Correct status codes | High |
| Error codes | Machine-readable codes | Medium |
| Messages | User-friendly messages | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Inconsistent error response
- **Severity:** High
- **Category:** API Design — Error Handling
- **File(s):** `app/Exceptions/Handler.php`
- **Lines:** 40-60
- **Finding:** Different error formats for different exception types
- **Standard:** API consistency — Uniform error structure
- **Recommendation:** Standardize on {message, code, details} format
- **Effort:** M
```

---

### 6.6 Route Naming Conventions

**What to Review:**
- Route names in `routes/api.php`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Naming | `resource.action` format | Low |
| Consistency | Same pattern everywhere | Low |
| Usage | Names used in redirects/links | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Inconsistent route naming
- **Severity:** Low
- **Category:** API Design — Naming
- **File(s):** `routes/api.php`
- **Lines:** 50-60
- **Finding:** Mixed naming conventions (kebab-case vs snake_case)
- **Standard:** Consistency — Uniform naming
- **Recommendation:** Standardize on `api.resource.action` format
- **Effort:** S
```

---

### 6.7 HTTP Status Code Correctness

**What to Review:**
- Controller responses
- Exception status codes

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| 200 OK | Successful GET/PUT/PATCH | Medium |
| 201 Created | Successful POST | Medium |
| 204 No Content | Successful DELETE | Low |
| 400 Bad Request | Validation errors | Medium |
| 401 Unauthorized | Auth required | Medium |
| 403 Forbidden | Permission denied | Medium |
| 404 Not Found | Resource missing | Medium |
| 422 Unprocessable | Validation failed | Medium |
| 500 Internal Error | Server errors | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Incorrect HTTP status code
- **Severity:** Medium
- **Category:** API Design — Status Codes
- **File(s):** `app/Http/Controllers/ExtensionCrudController.php`
- **Lines:** 45
- **Finding:** Store action returns 200 instead of 201
- **Standard:** HTTP — Created should return 201
- **Recommendation:** Change `response()->json()` to `response()->json(..., 201)`
- **Effort:** S
```

---

## Review Area 7: Test Coverage & Quality

### 7.1 Coverage Gaps

**What to Review:**
- Test directory structure
- Coverage reports if available

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Controller tests | All controllers have feature tests | High |
| Service tests | Complex services have unit tests | Medium |
| Model tests | Business logic in models tested | Medium |
| Policy tests | Authorization rules tested | High |
| Missing modules | Identify untested modules | High |

**Known Test Coverage:**
| Module | Status |
|--------|--------|
| Auth | Covered |
| AutoDialer | Covered |
| Broadcasting | Covered |
| CallNotifications | Covered |
| Platform | Covered |
| Security | Covered |
| Webhooks | Covered |
| IVR | Covered |
| Extensions | Covered |
| Voice Routing | Covered |
| Rate Limiting | Covered |
| Profile | Covered |

**Modules to Verify:**
- AI Assistants
- AI Load Balancers
- Business Hours
- Conference Rooms
- Distribution Lists
- Recordings
- Settings

**Output Template Entry:**
```markdown
### [CR-{n}] Missing test coverage
- **Severity:** High
- **Category:** Testing — Coverage
- **File(s):** `app/Services/AI/AlbsDistributionService.php`
- **Lines:** N/A
- **Finding:** Complex service (372 lines) has no unit tests
- **Standard:** Testing — Business logic must be tested
- **Recommendation:** Add unit tests for distribution algorithms
- **Effort:** L
```

---

### 7.2 Test Isolation

**What to Review:**
- Feature test setup
- Database state management

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| RefreshDatabase | Trait used in feature tests | High |
| No shared state | Tests don't depend on order | High |
| Mocking | External services mocked | Medium |
| Factory usage | Factories for test data | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Test not using RefreshDatabase
- **Severity:** High
- **Category:** Testing — Isolation
- **File(s):** `tests/Feature/ExtensionTest.php`
- **Lines:** 10-15
- **Finding:** Feature test modifies database without RefreshDatabase trait
- **Standard:** Testing — Tests must be isolated
- **Recommendation:** Add `use RefreshDatabase;` trait
- **Effort:** S
```

---

### 7.3 Assertion Quality

**What to Review:**
- Test assertions

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Specific assertions | Not just `assertTrue(true)` | Medium |
| Database assertions | Verify DB state when relevant | Medium |
| JSON structure | `assertJsonStructure` used | Medium |
| Status codes | HTTP status asserted | High |

**Output Template Entry:**
```markdown
### [CR-{n}] Weak test assertions
- **Severity:** Medium
- **Category:** Testing — Assertion Quality
- **File(s):** `tests/Feature/ExtensionTest.php`
- **Lines:** 45-50
- **Finding:** Test only asserts 200 status, doesn't verify response structure
- **Standard:** Testing — Assertions should verify behavior
- **Recommendation:** Add `assertJsonStructure` and database assertions
- **Effort:** S
```

---

### 7.4 Edge Case Coverage

**What to Review:**
- Test scenarios

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Validation errors | Invalid input tested | High |
| Not found | 404 scenarios tested | Medium |
| Auth failures | 401/403 scenarios tested | High |
| Boundary values | Min/max values tested | Medium |
| Empty input | Null/empty handling | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing edge case tests
- **Severity:** Medium
- **Category:** Testing — Edge Cases
- **File(s):** `tests/Feature/ExtensionTest.php`
- **Lines:** N/A
- **Finding:** No tests for invalid extension numbers, duplicate names
- **Standard:** Testing — Edge cases must be covered
- **Recommendation:** Add tests for validation failures and boundary values
- **Effort:** M
```

---

### 7.5 Integration Test Coverage

**What to Review:**
- Critical flow tests

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| End-to-end flows | Critical user journeys tested | High |
| Webhook handling | Webhook processing tested | High |
| Voice routing | CXML generation tested | High |
| Multi-tenancy | Tenant isolation tested | **Critical** |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing integration tests
- **Severity:** High
- **Category:** Testing — Integration
- **File(s):** `tests/Feature/`
- **Lines:** N/A
- **Finding:** No end-to-end test for voice routing flow
- **Standard:** Testing — Critical flows need integration tests
- **Recommendation:** Add test simulating webhook → routing → CXML response
- **Effort:** L
```

---

## Review Area 8: Documentation & Readability

### 8.1 PHPDoc Coverage

**What to Review:**
- Public methods in services
- Complex algorithms

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Method docs | All public methods documented | Low |
| Parameter types | @param with types | Low |
| Return types | @return documented | Low |
| Exceptions | @throws for exceptions | Low |
| Complex logic | Inline comments for algorithms | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing PHPDoc
- **Severity:** Low
- **Category:** Documentation — PHPDoc
- **File(s):** `app/Services/VoiceRouting/VoiceRoutingManager.php`
- **Lines:** 150-180
- **Finding:** Public method lacks documentation
- **Standard:** Documentation — Public APIs must be documented
- **Recommendation:** Add PHPDoc with param and return descriptions
- **Effort:** S
```

---

### 8.2 Inline Comment Quality

**What to Review:**
- Complex code sections

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Why not what | Comments explain reasoning | Medium |
| Algorithm docs | Complex algorithms explained | Medium |
| TODO/FIXME | Marked and tracked | Low |
| Outdated comments | Comments match code | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Outdated inline comment
- **Severity:** Medium
- **Category:** Documentation — Comments
- **File(s):** `app/Services/VoiceRouting/VoiceRoutingManager.php`
- **Lines:** 200
- **Finding:** Comment describes old algorithm, doesn't match current code
- **Standard:** Documentation — Comments must be accurate
- **Recommendation:** Update comment or remove if code is self-explanatory
- **Effort:** S
```

---

### 8.3 README/CONTRIBUTING Accuracy

**What to Review:**
- `README.md`
- `CONTRIBUTING.md`
- `AGENTS.md`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Setup instructions | Accurate and complete | Medium |
| Environment vars | All vars documented | Medium |
| Commands | Commands work as documented | Medium |
| Architecture | High-level description accurate | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Outdated setup instructions
- **Severity:** Medium
- **Category:** Documentation — README
- **File(s):** `README.md`
- **Lines:** 45-60
- **Finding:** Setup instructions reference old PHP version
- **Standard:** Documentation — Must be current
- **Recommendation:** Update to PHP 8.4 requirements
- **Effort:** S
```

---

### 8.4 OpenAPI Spec Accuracy

**What to Review:**
- OpenAPI/Swagger documentation if present

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Route coverage | All routes documented | Medium |
| Parameter accuracy | Params match implementation | High |
| Response schemas | Accurate response structures | High |
| Example values | Valid examples provided | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] OpenAPI spec out of sync
- **Severity:** High
- **Category:** Documentation — API Spec
- **File(s):** `openapi.yaml`
- **Lines:** 150-200
- **Finding:** Extension schema missing new `routingConfig` field
- **Standard:** Documentation — Spec must match implementation
- **Recommendation:** Regenerate or update OpenAPI spec
- **Effort:** M
```

---

### 8.5 Memory File Accuracy

**What to Review:**
- `/memory/` directory files

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| File lists | Accurate and complete | Medium |
| Route lists | Match actual routes | High |
| Model attributes | Match actual models | Medium |
| Service descriptions | Accurate descriptions | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Memory file out of date
- **Severity:** Medium
- **Category:** Documentation — Memory Files
- **File(s):** `memory/voice-routing-module.md`
- **Lines:** 20-30
- **Finding:** Lists 7 strategies, but code has 8
- **Standard:** Documentation — Memory files must be accurate
- **Recommendation:** Update memory file to reflect current code
- **Effort:** S
```

---

## Review Area 9: Infrastructure & Configuration

### 9.1 Docker Compose Correctness

**What to Review:**
- `docker-compose.yml`
- Service definitions

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Service health | Health checks defined | Medium |
| Dependencies | Proper depends_on | Medium |
| Volume persistence | Data volumes configured | High |
| Resource limits | Memory/CPU limits | Low |
| Restart policy | Appropriate policies | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing health check
- **Severity:** Medium
- **Category:** Infrastructure — Docker
- **File(s):** `docker-compose.yml`
- **Lines:** 45-60
- **Finding:** App service lacks health check definition
- **Standard:** Infrastructure — Services should have health checks
- **Recommendation:** Add healthcheck with curl to /health endpoint
- **Effort:** S
```

---

### 9.2 Environment Variable Documentation

**What to Review:**
- `.env.example`
- Configuration files

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Completeness | All vars in .env.example | High |
| Descriptions | Comments explain purpose | Medium |
| Defaults | Sensible defaults | Medium |
| Secrets | No secrets in example | **Critical** |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing env var documentation
- **Severity:** High
- **Category:** Infrastructure — Configuration
- **File(s):** `.env.example`
- **Lines:** N/A
- **Finding:** CLOUDONIX_WEBHOOK_SECRET not documented
- **Standard:** Configuration — All env vars must be documented
- **Recommendation:** Add to .env.example with description
- **Effort:** S
```

---

### 9.3 Nginx Configuration Quality

**What to Review:**
- `nginx.conf` or docker nginx config

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Security headers | Headers configured | Medium |
| Rate limiting | Limit zones defined | Medium |
| Buffer sizes | Appropriate for uploads | Low |
| Upstream config | Proper PHP-FPM config | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Missing security headers in nginx
- **Severity:** Medium
- **Category:** Infrastructure — Nginx
- **File(s):** `nginx/nginx.conf`
- **Lines:** 20-40
- **Finding:** No X-Frame-Options or X-Content-Type-Options headers
- **Standard:** Security — Security headers required
- **Recommendation:** Add security header configuration
- **Effort:** S
```

---

### 9.4 Health Endpoint Completeness

**What to Review:**
- Health check endpoints
- `routes/api.php` or `routes/web.php`

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Basic health | `/health` returns 200 | Medium |
| DB connectivity | Database check included | High |
| Redis connectivity | Redis check included | High |
| External deps | Critical external services checked | Medium |
| Response format | Consistent health response | Low |

**Output Template Entry:**
```markdown
### [CR-{n}] Incomplete health check
- **Severity:** High
- **Category:** Infrastructure — Health Checks
- **File(s):** `routes/api.php`
- **Lines:** 25
- **Finding:** Health endpoint doesn't verify database connectivity
- **Standard:** Infrastructure — Health checks must verify dependencies
- **Recommendation:** Add DB and Redis connectivity checks to health endpoint
- **Effort:** M
```

---

### 9.5 Queue Worker Configuration

**What to Review:**
- Queue configuration in `config/queue.php`
- Supervisor/docker worker config

**What to Look For:**
| Criterion | Pass Criteria | Severity |
|-----------|---------------|----------|
| Retry config | Appropriate retry counts | Medium |
| Timeout | Sensible job timeout | Medium |
| Failed job handling | Failed job config | High |
| Worker count | Appropriate for load | Low |
| Memory limits | Prevent memory leaks | Medium |

**Output Template Entry:**
```markdown
### [CR-{n}] Queue timeout misconfiguration
- **Severity:** Medium
- **Category:** Infrastructure — Queue
- **File(s):** `config/queue.php`
- **Lines:** 45
- **Finding:** Timeout (60s) shorter than some long-running jobs
- **Standard:** Queue — Timeout must exceed max job duration
- **Recommendation:** Increase timeout or move to batch processing
- **Effort:** S
```

---

## Severity Classification Guide

### Critical (🚨)
**Definition**: Security vulnerabilities, data loss risks, production-breaking bugs, tenant isolation breaches

**Examples**:
- Missing `declare(strict_types=1)`
- Missing tenant scope on model
- SQL injection vulnerability
- Missing authorization check
- Silent failure in catch block
- Race condition in critical path
- Queue job not idempotent
- Unchecked errors in Go
- Missing `DisableKeepAlives` in Go HTTP client

**Action**: Must fix before merge. Block PR if found.

---

### High (⚠️)
**Definition**: Performance issues, correctness bugs, significant maintainability problems, missing authorization

**Examples**:
- N+1 query
- Missing transaction for multi-step operation
- Business logic in controller
- Service violates SRP (very large classes)
- Missing error handling
- Incorrect HTTP status codes
- Missing test coverage for critical paths
- Missing health check for critical dependency
- Cache invalidation missing

**Action**: Should fix before merge. Requires justification to skip.

---

### Medium (💡)
**Definition**: Code smells, minor optimizations, documentation gaps, consistency issues

**Examples**:
- Code duplication
- Missing PHPDoc on public methods
- Inconsistent naming
- Missing empty states
- Weak test assertions
- Missing edge case tests
- Component too large
- Enum not backed by string

**Action**: Fix in follow-up PR or document as technical debt.

---

### Low (📝)
**Definition**: Style preferences, minor refactoring opportunities, cosmetic issues

**Examples**:
- Unused imports
- Inconsistent indentation
- Missing inline comments
- Outdated documentation
- Non-RESTful route naming
- Missing labels (accessibility)

**Action**: Fix when touching related code. Can batch into cleanup PRs.

---

## Output Template: Code Review Results Document

Create a new file at `/docs/review-workplan/code-review-{date}/CODE-REVIEW-{date}.md`:

```markdown
# Code Review Results — {YYYY-MM-DD}

## Executive Summary

**Review Date**: {date}  
**Reviewer**: {name}  
**Scope**: {full|partial|focused}  
**Files Reviewed**: {count}  

| Severity | Count | Status |
|----------|-------|--------|
| Critical | X | {X open, Y resolved} |
| High | X | {X open, Y resolved} |
| Medium | X | {X open, Y resolved} |
| Low | X | {X open, Y resolved} |

**Overall Assessment**: {Brief summary of code quality}

---

## Critical Issues (Must Fix)

### [CR-001] {Title}
- **Severity:** Critical
- **Category:** {review area}
- **File(s):** {file paths}
- **Lines:** {line numbers}
- **Finding:** {description}
- **Standard:** {violated standard}
- **Recommendation:** {remediation}
- **Effort:** {S/M/L}
- **Status:** {Open|In Progress|Resolved}

---

## High Priority Issues (Should Fix)

### [CR-010] {Title}
- **Severity:** High
- **Category:** {review area}
- **File(s):** {file paths}
- **Lines:** {line numbers}
- **Finding:** {description}
- **Standard:** {violated standard}
- **Recommendation:** {remediation}
- **Effort:** {S/M/L}
- **Status:** {Open|In Progress|Resolved}

---

## Medium Priority Issues (Fix When Convenient)

### [CR-020] {Title}
- **Severity:** Medium
- **Category:** {review area}
- **File(s):** {file paths}
- **Lines:** {line numbers}
- **Finding:** {description}
- **Standard:** {violated standard}
- **Recommendation:** {remediation}
- **Effort:** {S/M/L}
- **Status:** {Open|In Progress|Resolved}

---

## Low Priority Issues (Cleanup)

### [CR-030] {Title}
- **Severity:** Low
- **Category:** {review area}
- **File(s):** {file paths}
- **Lines:** {line numbers}
- **Finding:** {description}
- **Standard:** {violated standard}
- **Recommendation:** {remediation}
- **Effort:** {S/M/L}
- **Status:** {Open|In Progress|Resolved}

---

## Positive Findings

### ✅ {Good Practice Title}
- **Category:** {area}
- **File(s):** {files}
- **Observation:** {description of good practice observed}

---

## Recommendations Summary

| Priority | Issue | Effort | Owner |
|----------|-------|--------|-------|
| P0 | CR-001: ... | S | @developer |
| P0 | CR-002: ... | M | @developer |
| P1 | CR-010: ... | L | @developer |

---

## Appendix: Review Checklist

- [ ] PHP Backend — PSR-12 compliance
- [ ] PHP Backend — Security (tenant isolation, auth)
- [ ] PHP Backend — Performance (N+1, transactions)
- [ ] Frontend — TypeScript type safety
- [ ] Frontend — Component structure
- [ ] Go — Error handling
- [ ] Architecture — Pattern compliance
- [ ] Database — Migration quality
- [ ] API — REST conventions
- [ ] Tests — Coverage gaps
- [ ] Documentation — Accuracy
- [ ] Infrastructure — Configuration

---

*Generated from CODE-REVIEW-WORKPLAN.md v1.0*
```

---

## Review Workflow

### Step 1: Pre-Review (15 min)
1. Read PR description and linked issues
2. Check out branch and run linting/tests
3. Identify which review areas apply

### Step 2: Critical Path Review (30 min)
1. Review Area 1: PHP Security (tenant isolation, auth)
2. Review Area 5: Database (scopes, integrity)
3. Review Area 6: API (auth bypass risks)

### Step 3: Quality Review (45 min)
1. Review Area 1: PHP Quality (remaining items)
2. Review Area 2: Frontend
3. Review Area 3: Go Dialer Worker

### Step 4: Deep Dive (30 min)
1. Review Area 4: Architecture
2. Review Area 7: Tests
3. Review Area 8: Documentation
4. Review Area 9: Infrastructure

### Step 5: Documentation (20 min)
1. Create findings document
2. Classify severities
3. Write recommendations
4. Submit review

---

## Review Best Practices

### Do:
- ✅ Review in priority order (Critical → High → Medium → Low)
- ✅ Provide specific file:line references
- ✅ Include code snippets showing the issue
- ✅ Suggest concrete fixes, not just problems
- ✅ Acknowledge good practices when found
- ✅ Be constructive and educational
- ✅ Link to relevant standards (AGENTS.md, PSR-12, etc.)

### Don't:
- ❌ Block on style-only issues (use automated tools)
- ❌ Nitpick without justification
- ❌ Assume intent — ask if unclear
- ❌ Review without understanding context
- ❌ Skip security checks for "small" PRs

---

## References

- [AGENTS.md](/AGENTS.md) — Project coding standards
- [Laravel Docs](https://laravel.com/docs/12.x) — Framework documentation
- [PSR-12](https://www.php-fig.org/psr/psr-12/) — PHP coding standard
- [Cloudonix Docs](https://developers.cloudonix.com/) — Voice platform docs
- [Prior Review](/docs/FULL-CODE-AND-SECURITY-REVIEW-2026-02-06.md) — Feb 2026 review

---

**Document Version**: 1.0  
**Last Updated**: 2026-04-09  
**Maintained By**: Code Review Team
