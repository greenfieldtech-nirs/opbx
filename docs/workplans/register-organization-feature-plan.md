# Register New Organization Feature Implementation Plan

## Overview

This feature enables self-service organization registration with automatic owner account creation and immediate dashboard access.

**Feature Summary:**
- Public registration endpoint (no authentication required)
- Organization creation with validation (name, timezone, slug)
- Admin user creation with secure password requirements
- Automatic login upon successful registration
- Rate limiting to prevent abuse

**Timeline:** 10-12 working days
**Priority:** High (core onboarding feature)
**Dependencies:** None

---

## Step-by-Step Implementation Plan

### Phase 1: Backend Foundation

#### Step 1.1: Create RegisterRequest Form Validation
**File:** `app/Http/Requests/RegisterRequest.php`

**Tasks:**
- [ ] Create FormRequest class with organization validation rules
  - name: required, 2-100 chars, unique across organizations
  - timezone: required, valid PHP timezone
  - slug: optional, 2-50 chars, alphanumeric + hyphen, unique
- [ ] Create admin validation rules
  - name: required, 2-100 chars
  - email: required, valid email, unique across all users
  - password: required, min 8 chars, mixed case, numbers, symbols, confirmed
- [ ] Add custom validation messages
- [ ] Add slug auto-generation in validated() method
- [ ] Write unit tests for validation rules

**Validation Rules:**
```php
'organization.name' => 'required|string|min:2|max:100'
'organization.timezone' => 'required|timezone:all'
'organization.slug' => 'nullable|string|min:2|max:50|regex:/^[a-z0-9\-]+$/'
'admin.name' => 'required|string|min:2|max:100'
'admin.email' => 'required|email|max:255'
'admin.password' => 'required|string|min:8|mixedCase|numbers|symbols|confirmed'
```

#### Step 1.2: Create RegisterController
**File:** `app/Http/Controllers/Api/RegisterController.php`

**Tasks:**
- [ ] Create controller with register() method
- [ ] Implement database transaction for atomic operation
- [ ] Create organization with default settings
- [ ] Create owner user with hashed password
- [ ] Generate Sanctum token
- [ ] Return standardized response
- [ ] Add proper error handling and logging
- [ ] Write unit tests for controller

**Controller Methods:**
```php
public function register(RegisterRequest $request): JsonResponse
public function validateRegistration(Request $request): JsonResponse
```

#### Step 1.3: Add Route Definitions
**File:** `routes/api.php`

**Tasks:**
- [ ] Add POST /api/v1/register route
- [ ] Add GET /api/v1/register/validate route
- [ ] Apply registration rate limiter
- [ ] Apply throttle:registration middleware

**Route Definition:**
```php
Route::post('/register', [RegisterController::class, 'register'])
    ->middleware('throttle:registration')
    ->name('auth.register');

Route::get('/register/validate', [RegisterController::class, 'validateRegistration'])
    ->name('auth.register.validate');
```

#### Step 1.4: Configure Rate Limiter
**File:** `app/Providers/RouteServiceProvider.php`

**Tasks:**
- [ ] Add registration rate limiter configuration
- [ ] Configure max_attempts: 10
- [ ] Configure decay_minutes: 60

**Configuration:**
```php
RateLimiter::for('registration', function (Request $request) {
    return Limit::perHour(10)->by($request->ip());
});
```

#### Step 1.5: Backend Unit Testing
**File:** `tests/Feature/RegisterControllerTest.php`

**Tasks:**
- [ ] Test successful registration
- [ ] Test duplicate organization name validation
- [ ] Test duplicate email validation
- [ ] Test password complexity requirements
- [ ] Test invalid timezone handling
- [ ] Test rate limiting enforcement
- [ ] Test transaction rollback on failure

---

### Phase 2: Frontend Foundation

#### Step 2.1: Add TypeScript Types
**File:** `frontend/src/types/api.types.ts`

**Tasks:**
- [ ] Add RegisterRequest interface
- [ ] Add ValidationResult interface
- [ ] Add RegisterResponse interface

**Types:**
```typescript
export interface RegisterRequest {
    organization: {
        name: string;
        timezone: string;
        slug?: string;
    };
    admin: {
        name: string;
        email: string;
        password: string;
        password_confirmation: string;
    };
}
```

#### Step 2.2: Update Auth Service
**File:** `frontend/src/services/auth.service.ts`

**Tasks:**
- [ ] Add register() method
- [ ] Add validateRegistration() method
- [ ] Integrate with API client

**Service Methods:**
```typescript
register: (data: RegisterRequest): Promise<LoginResponse> => {
    return api.post<LoginResponse>('/register', data).then(res => res.data);
},
validateRegistration: (params: { organization_name?: string; admin_email?: string; slug?: string; }) => {
    return api.get('/register/validate', { params }).then(res => res.data);
}
```

#### Step 2.3: Create Registration Page
**File:** `frontend/src/pages/Register.tsx`

**Tasks:**
- [ ] Create two-column layout (matching Login.tsx style)
- [ ] Implement Zod validation schema
- [ ] Add organization form section
- [ ] Add admin account form section
- [ ] Add timezone selector
- [ ] Add password strength indicator
- [ ] Add real-time validation
- [ ] Add loading states
- [ ] Add error handling with toast notifications

**Form Sections:**
1. Organization Information
   - Organization Name (with availability check)
   - Timezone Selector (grouped by region)
   - Organization Slug (optional, auto-generated)

2. Admin Account
   - Full Name
   - Email Address (with availability check)
   - Password (with strength meter)
   - Confirm Password

#### Step 2.4: Update Router
**File:** `frontend/src/router.tsx`

**Tasks:**
- [ ] Import Register page
- [ ] Add /register route (public, no auth required)

**Route:**
```typescript
{
    path: '/register',
    element: <Register />,
}
```

#### Step 2.5: Add Registration Link to Login
**File:** `frontend/src/pages/Login.tsx`

**Tasks:**
- [ ] Add link to registration page below login form

**UI:**
```tsx
<p className="text-sm text-muted-foreground">
    Don't have an account?{' '}
    <a href="/register" className="text-blue-600 hover:text-blue-800 font-medium">
        Register your organization
    </a>
</p>
```

#### Step 2.6: Update Auth Context
**File:** `frontend/src/context/AuthContext.tsx`

**Tasks:**
- [ ] Add register() method to auth context
- [ ] Integrate with auth service
- [ ] Handle registration response

**Context Method:**
```typescript
const register = async (data: RegisterRequest, onSuccess?: () => void): Promise<void> => {
    const response = await authService.register(data);
    storage.setToken(response.access_token);
    setUser(response.user);
    setIsAuthenticated(true);
    onSuccess?.();
}
```

---

### Phase 3: Integration and Polish

#### Step 3.1: Update Login Page Redirect
**File:** `frontend/src/pages/Register.tsx`

**Tasks:**
- [ ] On successful registration, redirect to /dashboard
- [ ] Show success toast notification

**Redirect Logic:**
```typescript
const onSubmit = async (data: RegisterFormData) => {
    try {
        await register(data, () => navigate('/dashboard'));
        toast.success('Organization registered successfully!');
    } catch (error) {
        toast.error(getErrorMessage(error));
    }
};
```

#### Step 3.2: Cross-Browser Testing
**Tasks:**
- [ ] Test on Chrome, Firefox, Safari, Edge
- [ ] Test responsive layout on mobile devices
- [ ] Verify form validation works consistently

#### Step 3.3: Accessibility Testing
**Tasks:**
- [ ] Verify keyboard navigation works
- [ ] Check ARIA labels on form fields
- [ ] Test screen reader compatibility
- [ ] Verify color contrast requirements

#### Step 3.4: Security Review
**Tasks:**
- [ ] Verify rate limiting is working
- [ ] Check password hashing is applied
- [ ] Verify no sensitive data in logs
- [ ] Test SQL injection prevention
- [ ] Verify CSRF protection

#### Step 3.5: Documentation Update
**Tasks:**
- [ ] Update API documentation
- [ ] Add user guide for registration
- [ ] Document environment variables (if any)

---

### Phase 4: Final Testing

#### Step 4.1: End-to-End Testing
**Tasks:**
- [ ] Test complete registration flow
- [ ] Test validation at each step
- [ ] Test error handling scenarios
- [ ] Test rate limiting behavior

#### Step 4.2: Performance Testing
**Tasks:**
- [ ] Measure registration completion time
- [ ] Verify API response time < 500ms
- [ ] Check page load time < 1 second

#### Step 4.3: Bug Fixes and Polish
**Tasks:**
- [ ] Fix any discovered issues
- [ ] Refactor code for clarity
- [ ] Add comments where needed
- [ ] Final code review

---

## File Checklist

### New Files to Create

| File | Phase | Description |
|------|-------|-------------|
| `app/Http/Requests/RegisterRequest.php` | 1.1 | Form validation |
| `app/Http/Controllers/Api/RegisterController.php` | 1.2 | Registration controller |
| `tests/Feature/RegisterControllerTest.php` | 1.5 | Unit tests |
| `frontend/src/pages/Register.tsx` | 2.3 | Registration page |

### Files to Modify

| File | Phase | Change |
|------|-------|--------|
| `routes/api.php` | 1.3 | Add register routes |
| `app/Providers/RouteServiceProvider.php` | 1.4 | Add rate limiter |
| `frontend/src/types/api.types.ts` | 2.1 | Add types |
| `frontend/src/services/auth.service.ts` | 2.2 | Add service methods |
| `frontend/src/router.tsx` | 2.4 | Add route |
| `frontend/src/pages/Login.tsx` | 2.5 | Add link |
| `frontend/src/context/AuthContext.tsx` | 2.6 | Add register method |

---

## Testing Checklist

### Unit Tests (PHPUnit)
- [ ] test_successful_registration
- [ ] test_duplicate_organization_name_fails
- [ ] test_duplicate_email_fails
- [ ] test_password_complexity_enforced
- [ ] test_invalid_timezone_fails
- [ ] test_rate_limiting_enforced
- [ ] test_transaction_rollback_on_failure

### Frontend Tests (Cypress/Playwright)
- [ ] successful_registration_flow
- [ ] validation_error_display
- [ ] password_strength_indicator
- [ ] timezone_selection
- [ ] auto_redirect_after_success
- [ ] loading_states
- [ ] error_handling

---

## Acceptance Criteria

### Functional
- [ ] User can access /register
- [ ] Organization name uniqueness validated
- [ ] Admin email uniqueness validated
- [ ] Password complexity enforced
- [ ] Registration succeeds in < 2 seconds
- [ ] User redirected to dashboard
- [ ] Authentication token received

### Security
- [ ] Rate limiting prevents abuse
- [ ] Passwords are hashed
- [ ] No sensitive data in logs
- [ ] SQL injection prevented
- [ ] XSS prevented

### Performance
- [ ] Registration completes in < 2 seconds
- [ ] Page loads in < 1 second
- [ ] API responds in < 500ms

---

## Dependencies

### External Dependencies
- Laravel 12.x (existing)
- React 18.x (existing)
- Zod (existing)
- Laravel Sanctum (existing)

### Internal Dependencies
- Organization model (existing)
- User model (existing)
- Auth infrastructure (existing)
- Timezone utilities (existing)

---

## Risk Assessment

| Risk | Impact | Probability | Mitigation |
|------|--------|-------------|------------|
| Email enumeration via validation | Medium | Low | Generic error messages |
| Rate limit bypass | Medium | Low | Multiple rate limit types |
| Password brute force | High | Low | Strong rate limiting |
| Transaction failure mid-registration | Low | Low | Database transactions |

---

## Timeline

| Phase | Days | Total |
|-------|------|-------|
| Phase 1: Backend | 4 | 4 |
| Phase 2: Frontend | 4 | 8 |
| Phase 3: Integration | 2 | 10 |
| Phase 4: Testing | 2 | 12 |

**Total:** 12 working days

---

## Success Metrics

| Metric | Target |
|--------|--------|
| Registration completion rate | > 60% |
| Average registration time | < 2 minutes |
| Registration error rate | < 5% |
| Page load time | < 1 second |
| API response time | < 500ms |
