# Email Validation Implementation Plan
## UserCheck.com Integration for OpBX

**Document Version:** 2.0  
**Date:** 2026-02-18  
**Status:** Draft - Pending Review  
**Author:** Engineering Team  

---

## Executive Summary

This document outlines the implementation plan for integrating UserCheck.com email validation service into the OpBX application. The integration will validate user email addresses during registration to prevent disposable emails, role accounts, and other low-quality addresses from being used.

**IMPORTANT:** UserCheck is a **blocking test**. If the API is unavailable or returns an error, registration **MUST be blocked** for security reasons.

**API Token (Production):** `prd_Awa86sbrFKSLu2gy3KbBkL5e8VS0`  
**Service Endpoint:** `https://api.usercheck.com/email/{email}`  
**Authentication:** Bearer Token (RFC 6750)

---

## Table of Contents

1. [Requirements Analysis](#1-requirements-analysis)
2. [Architecture Overview](#2-architecture-overview)
3. [Implementation Phases](#3-implementation-phases)
4. [Technical Specifications](#4-technical-specifications)
5. [Security Considerations](#5-security-considerations)
6. [Testing Strategy](#6-testing-strategy)
7. [Deployment Plan](#7-deployment-plan)
8. [Risks and Mitigation](#8-risks-and-mitigation)

---

## 1. Requirements Analysis

### 1.1 Functional Requirements

| ID | Requirement | Priority | Acceptance Criteria |
|----|-------------|----------|---------------------|
| FR-001 | Validate email during user registration | Must Have | Email validation occurs before account creation |
| FR-002 | Block disposable email domains | Must Have | Reject emails where `disposable: true` (configurable) |
| FR-003 | Block role accounts | Must Have | Reject `role_account: true` (configurable) |
| FR-004 | Block blocklisted domains | Must Have | Reject `blocklisted: true` (configurable) |
| FR-005 | Block spam domains | Must Have | Reject `spam: true` (configurable) |
| FR-006 | Suggest typo corrections | Should Have | Display `did_you_mean` suggestion when available |
| FR-007 | API failure blocks registration | Must Have | If UserCheck API fails, registration is denied |
| FR-008 | Each check is independently configurable | Must Have | All validation parameters controlled via .env |

### 1.2 Non-Functional Requirements

| ID | Requirement | Target |
|----|-------------|--------|
| NFR-001 | API Response Time | < 5 seconds (hard timeout) |
| NFR-002 | No Caching | Real-time validation only (registration-time only) |
| NFR-003 | Data Retention | Don't store raw emails in logs |

### 1.3 UserCheck API Response Fields to Utilize

```json
{
  "status": 200,
  "email": "user@example.com",
  "disposable": false,           // CONFIGURABLE: Block if true
  "public_domain": false,        // INFO: Gmail, Yahoo, etc.
  "relay_domain": false,         // INFO: Forwarding services
  "role_account": false,         // CONFIGURABLE: Block if true
  "did_you_mean": null,          // UX: Suggest corrections
  "blocklisted": false,          // CONFIGURABLE: Block if true
  "spam": false,                 // CONFIGURABLE: Block if true
  "mx": true                     // INFO: Valid MX records
}
```

---

## 2. Architecture Overview

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         Client (Browser)                        │
│  ┌─────────────────┐  ┌─────────────────────────────────────┐  │
│  │ Registration    │  │ Optional: Async Email Validation    │  │
│  │ Form            │  │ (debounced, 300ms)                  │  │
│  └────────┬────────┘  └──────────────────┬──────────────────┘  │
└───────────┼────────────────────────────────┼────────────────────┘
            │                                │
            │ POST /api/auth/register        │ GET /api/validate-email
            │                                │
┌───────────▼────────────────────────────────▼────────────────────┐
│                     Laravel Backend (OpBX)                      │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                    Validation Layer                        │  │
│  │  ┌──────────────┐  ┌──────────────┐                       │  │
│  │  │ Form Request │──│ UserCheck    │                       │  │
│  │  │ Validator    │  │ Service      │                       │  │
│  │  └──────────────┘  └──────────────┘                       │  │
│  └───────────────────────────────────────────────────────────┘  │
│                            │                                    │
│  ┌─────────────────────────▼────────────────────────────────┐  │
│  │              UserCheck.com API Service                   │  │
│  │  • HTTP Client with timeout (5s)                         │  │
│  │  • Hard timeout = Registration blocked                   │  │
│  │  • Bearer Token: prd_Awa86sbrFKSLu2gy3KbBkL5e8VS0        │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Service Architecture

```
app/
├── Services/
│   ├── EmailValidation/
│   │   ├── Contracts/
│   │   │   └── EmailValidatorInterface.php
│   │   ├── UserCheckEmailValidator.php      # Primary implementation
│   │   └── DTOs/
│   │       ├── EmailValidationRequest.php
│   │       └── EmailValidationResult.php
│   └── ...
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── EmailValidationController.php  # Async validation endpoint
│   └── Requests/
│       └── Auth/
│           └── RegisterRequest.php            # Updated with email validation
└── ...
```

---

## 3. Implementation Phases

### Phase 1: Core Service Layer (Estimated: 2 days)

**Goal:** Build the foundational email validation service with configurable validation rules

#### 3.1.1 Create Service Contract
- [ ] Define `EmailValidatorInterface` with `validate(string $email): EmailValidationResult`
- [ ] Document expected behavior: API failure = validation failure

#### 3.1.2 Implement UserCheck API Client
- [ ] Create `UserCheckEmailValidator` class
- [ ] Configure HTTP client with:
  - Base URL: `https://api.usercheck.com`
  - Timeout: 5 seconds (hard limit)
  - No retries (fail fast)
  - Bearer token from env: `USERCHECK_API_TOKEN`
- [ ] Handle HTTP response codes:
  - `200`: Success - parse JSON response, apply validation rules
  - `400`: Invalid email format - validation fails
  - `429`: Rate limit - validation fails (registration blocked)
  - `5xx`: Server error - validation fails (registration blocked)
  - Timeout - validation fails (registration blocked)

#### 3.1.3 Create DTOs
- [ ] `EmailValidationRequest`: Encapsulates email string
- [ ] `EmailValidationResult`:
  ```php
  class EmailValidationResult
  {
      public bool $isValid;           // Overall validation result
      public bool $isDisposable;      // disposable flag from API
      public bool $isBlocklisted;     // blocklisted flag from API
      public bool $isSpam;            // spam flag from API
      public bool $isRoleAccount;     // role_account flag from API
      public ?string $suggestion;     // did_you_mean from API
      public ?string $errorMessage;   // Error description if validation failed
      public string $checkedEmail;    // The email that was checked
      public ?string $normalizedEmail; // normalized_email from API
      public ?string $failedReason;    // Which check failed (disposable, spam, etc.)
  }
  ```

#### 3.1.4 Configuration - Environment-Based Validation Rules
- [ ] Add to `config/services.php`:
  ```php
  'usercheck' => [
      'enabled' => env('USERCHECK_ENABLED', true),
      'api_token' => env('USERCHECK_API_TOKEN'),
      'base_url' => env('USERCHECK_BASE_URL', 'https://api.usercheck.com'),
      'timeout' => env('USERCHECK_TIMEOUT', 5),
      
      // Individual validation rule toggles (all default to true for strict validation)
      'block_disposable' => env('USERCHECK_BLOCK_DISPOSABLE', true),
      'block_blocklisted' => env('USERCHECK_BLOCK_BLOCKLISTED', true),
      'block_spam' => env('USERCHECK_BLOCK_SPAM', true),
      'block_role_accounts' => env('USERCHECK_BLOCK_ROLE_ACCOUNTS', true),
      'block_relay_domains' => env('USERCHECK_BLOCK_RELAY_DOMAINS', false),
      'block_public_domains' => env('USERCHECK_BLOCK_PUBLIC_DOMAINS', false),
  ],
  ```

#### 3.1.5 Environment Variables
- [ ] Add to `.env.example`:
  ```
  # UserCheck Email Validation Service
  USERCHECK_ENABLED=true
  USERCHECK_API_TOKEN=prd_Awa86sbrFKSLu2gy3KbBkL5e8VS0
  USERCHECK_BASE_URL=https://api.usercheck.com
  USERCHECK_TIMEOUT=5
  
  # Validation Rules - Set to false to allow specific email types
  USERCHECK_BLOCK_DISPOSABLE=true
  USERCHECK_BLOCK_BLOCKLISTED=true
  USERCHECK_BLOCK_SPAM=true
  USERCHECK_BLOCK_ROLE_ACCOUNTS=true
  USERCHECK_BLOCK_RELAY_DOMAINS=false
  USERCHECK_BLOCK_PUBLIC_DOMAINS=false
  ```

**Deliverables:**
- Service classes implemented
- Unit tests for UserCheckEmailValidator
- Configuration files updated

---

### Phase 2: Validation Rules & Form Integration (Estimated: 2 days)

**Goal:** Integrate validation into registration flow with detailed error messages

#### 3.2.1 Create Laravel Validation Rule
- [ ] Create `app/Rules/ValidEmailDomain.php`:
  ```php
  class ValidEmailDomain implements ValidationRule
  {
      public function validate(string $attribute, mixed $value, Closure $fail): void
      {
          $result = app(EmailValidatorInterface::class)->validate($value);
          
          if (!$result->isValid) {
              $fail($this->getErrorMessage($result));
          }
      }
      
      private function getErrorMessage(EmailValidationResult $result): string
      {
          // Return specific message based on which check failed
          if ($result->isDisposable) {
              return 'Disposable email addresses are not allowed.';
          }
          if ($result->isBlocklisted) {
              return 'This email domain is not allowed.';
          }
          if ($result->isSpam) {
              return 'This email address cannot be used.';
          }
          if ($result->isRoleAccount) {
              return 'Role-based email addresses (e.g., admin@, info@) are not allowed.';
          }
          if ($result->suggestion) {
              return "Did you mean {$result->suggestion}?";
          }
          if ($result->errorMessage) {
              return $result->errorMessage;
          }
          return 'Email validation failed. Please try again.';
      }
  }
  ```

#### 3.2.2 Update Registration Request
- [ ] Modify `app/Http/Requests/Auth/RegisterRequest.php`:
  ```php
  public function rules(): array
  {
      return [
          'email' => [
              'required',
              'email',
              'unique:users,email',
              new ValidEmailDomain(),  // UserCheck validation - BLOCKING
          ],
          // ... other rules
      ];
  }
  ```

#### 3.2.3 Error Messages
- [ ] Add translations to `lang/en/validation.php`:
  ```php
  'email_disposable' => 'Disposable email addresses are not allowed.',
  'email_blocklisted' => 'This email domain is not allowed.',
  'email_spam' => 'This email address cannot be used.',
  'email_role_account' => 'Role-based email addresses (e.g., admin@, info@) are not allowed.',
  'email_relay_domain' => 'Email forwarding addresses are not allowed.',
  'email_public_domain' => 'Public email providers are not allowed for this registration.',
  'email_suggestion' => 'Did you mean :suggestion?',
  'email_validation_failed' => 'Unable to validate email address. Please try again later.',
  ```

#### 3.2.4 Suggestion Handling
- [ ] If `did_you_mean` is present, include it in error message
- [ ] Example: "Did you mean user@gmail.com?"

**Deliverables:**
- Validation rule implemented
- Registration form updated
- Localization strings added

---

### Phase 3: Async Validation API (Estimated: 2 days)

**Goal:** Provide real-time email validation for better UX (optional, same blocking rules apply)

#### 3.3.1 Create API Endpoint
- [ ] Route: `GET /api/v1/validate-email?email={email}`
- [ ] Controller: `EmailValidationController@validate`
- [ ] Rate limit: 10 requests per minute per IP
- [ ] Response format:
  ```json
  {
    "valid": true,
    "disposable": false,
    "blocklisted": false,
    "spam": false,
    "role_account": false,
    "suggestion": null,
    "message": null
  }
  ```
- [ ] **Important:** If API call fails, return `valid: false` with error message

#### 3.3.2 Security Considerations
- [ ] Implement rate limiting to prevent email enumeration
- [ ] Don't reveal if email exists in database
- [ ] Only return validation result, not raw API response

**Deliverables:**
- API endpoint implemented
- Rate limiting configured
- API documentation updated

---

### Phase 4: Frontend Integration (Estimated: 2 days)

**Goal:** Provide immediate feedback in registration form

#### 3.4.1 Create Email Validation Hook
- [ ] `useEmailValidation(email: string)` hook
- [ ] Debounce: 300ms after user stops typing
- [ ] States: `idle` | `validating` | `valid` | `invalid` | `error`
- [ ] **On API error:** Show error state (don't allow submission)

#### 3.4.2 Update Registration Form
- [ ] Add validation indicator next to email field
- [ ] Show spinner while validating
- [ ] Show checkmark for valid emails
- [ ] Show error with specific message for invalid emails
- [ ] **Block form submission if validation API returns error**

#### 3.4.3 UX Improvements
- [ ] Show specific error based on validation failure type
- [ ] Display typo suggestion as clickable correction

**Deliverables:**
- React hook implemented
- Registration form updated
- E2E tests for validation flow

---

### Phase 5: Monitoring & Logging (Estimated: 1 day)

**Goal:** Track validation effectiveness and API health

#### 3.5.1 Metrics to Track
- [ ] Validation requests per minute
- [ ] API response times (p50, p95, p99)
- [ ] API error rate (critical: alerts if > 1%)
- [ ] Blocked email types (disposable, spam, role, etc.)
- [ ] Registration failures due to email validation

#### 3.5.2 Logging
- [ ] Log validation failures (without raw email):
  ```php
  Log::info('Email validation failed', [
      'reason' => 'disposable', // or 'spam', 'blocklisted', 'role_account', 'api_error'
      'domain_hash' => hash('sha256', $domain),
  ]);
  ```
- [ ] Log API errors with context (alert if frequent)
- [ ] **Alert:** If API error rate exceeds threshold, page on-call engineer

#### 3.5.3 Health Check
- [ ] Add UserCheck API to health check endpoint
- [ ] Return CRITICAL status if API is failing (registration is blocked!)

**Deliverables:**
- Metrics collection implemented
- Structured logging added
- Health check updated
- Alerting rules configured

---

## 4. Technical Specifications

### 4.1 API Request Format

```http
GET https://api.usercheck.com/email/user@example.com
Authorization: Bearer prd_Awa86sbrFKSLu2gy3KbBkL5e8VS0
Accept: application/json
```

### 4.2 Validation Logic (Configurable via .env)

```php
public function isEmailAcceptable(EmailValidationResult $result): bool
{
    // Check disposable (configurable)
    if (config('services.usercheck.block_disposable') && $result->isDisposable) {
        $result->failedReason = 'disposable';
        return false;
    }
    
    // Check blocklisted (configurable)
    if (config('services.usercheck.block_blocklisted') && $result->isBlocklisted) {
        $result->failedReason = 'blocklisted';
        return false;
    }
    
    // Check spam (configurable)
    if (config('services.usercheck.block_spam') && $result->isSpam) {
        $result->failedReason = 'spam';
        return false;
    }
    
    // Check role accounts (configurable)
    if (config('services.usercheck.block_role_accounts') && $result->isRoleAccount) {
        $result->failedReason = 'role_account';
        return false;
    }
    
    // Check relay domains (configurable)
    if (config('services.usercheck.block_relay_domains') && $result->isRelayDomain) {
        $result->failedReason = 'relay_domain';
        return false;
    }
    
    // Check public domains (configurable - typically false)
    if (config('services.usercheck.block_public_domains') && $result->isPublicDomain) {
        $result->failedReason = 'public_domain';
        return false;
    }
    
    return true;
}
```

### 4.3 Error Handling Matrix (FAIL CLOSED)

| Scenario | Behavior | User Message |
|----------|----------|--------------|
| Disposable email | **BLOCK** | "Disposable email addresses are not allowed." |
| Blocklisted domain | **BLOCK** | "This email domain is not allowed." |
| Spam domain | **BLOCK** | "This email address cannot be used." |
| Role account (if enabled) | **BLOCK** | "Role-based email addresses are not allowed." |
| Relay domain (if enabled) | **BLOCK** | "Email forwarding addresses are not allowed." |
| Public domain (if enabled) | **BLOCK** | "Public email providers are not allowed." |
| Invalid format | **BLOCK** | "Please enter a valid email address." |
| API rate limit (429) | **BLOCK** | "Unable to validate email. Please try again later." |
| API timeout/error | **BLOCK** | "Unable to validate email. Please try again later." |
| Typo detected | **BLOCK** with suggestion | "Did you mean {suggestion}?" |

### 4.4 Configuration Examples

**Strict Mode (Default):**
```env
USERCHECK_BLOCK_DISPOSABLE=true
USERCHECK_BLOCK_BLOCKLISTED=true
USERCHECK_BLOCK_SPAM=true
USERCHECK_BLOCK_ROLE_ACCOUNTS=true
USERCHECK_BLOCK_RELAY_DOMAINS=false
USERCHECK_BLOCK_PUBLIC_DOMAINS=false
```

**Relaxed Mode (Allow role accounts):**
```env
USERCHECK_BLOCK_DISPOSABLE=true
USERCHECK_BLOCK_BLOCKLISTED=true
USERCHECK_BLOCK_SPAM=true
USERCHECK_BLOCK_ROLE_ACCOUNTS=false
USERCHECK_BLOCK_RELAY_DOMAINS=false
USERCHECK_BLOCK_PUBLIC_DOMAINS=false
```

**Maximum Security:**
```env
USERCHECK_BLOCK_DISPOSABLE=true
USERCHECK_BLOCK_BLOCKLISTED=true
USERCHECK_BLOCK_SPAM=true
USERCHECK_BLOCK_ROLE_ACCOUNTS=true
USERCHECK_BLOCK_RELAY_DOMAINS=true
USERCHECK_BLOCK_PUBLIC_DOMAINS=false
```

---

## 5. Security Considerations

### 5.1 API Token Security
- [ ] Store token in environment variable only
- [ ] Never log the token
- [ ] Rotate token if compromised (via UserCheck dashboard)

### 5.2 Data Privacy
- [ ] Don't store raw emails in logs (use hashes)
- [ ] Consider GDPR implications (UserCheck is EU-hosted, GDPR compliant)

### 5.3 Rate Limiting
- [ ] Implement per-IP rate limiting on validation endpoint
- [ ] Implement per-email rate limiting to prevent enumeration

### 5.4 FAIL CLOSED Strategy (CRITICAL)
- [ ] When UserCheck API fails, **BLOCK registration**
- [ ] Log incident for ops team investigation
- [ ] Display user-friendly error: "Unable to validate email. Please try again later."
- [ ] Set up alerts for API failures (this is a production issue!)

---

## 6. Testing Strategy

### 6.1 Unit Tests

| Component | Test Cases |
|-----------|------------|
| UserCheckEmailValidator | Success response, 400 error, 429 error, timeout, connection error |
| Validation Rules | Each rule toggle works independently, combinations work correctly |
| ValidEmailDomain Rule | Disposable blocked, spam blocked, role blocked (if enabled), valid allowed |

### 6.2 Integration Tests

| Scenario | Test |
|----------|------|
| Registration with disposable email | Should FAIL with appropriate message |
| Registration with Gmail | Should succeed (if public domains allowed) |
| Registration when API returns 500 | Should FAIL with "unable to validate" message |
| Registration when API times out | Should FAIL with "unable to validate" message |
| Async validation endpoint | Should return correct result |
| Config toggle OFF | Should allow previously blocked email type |

### 6.3 Test Data

Use these test emails for validation:

| Email | Expected Result |
|-------|-----------------|
| `test@gmail.com` | Valid (public domain, not blocked) |
| `admin@company.com` | Blocked (role account, if enabled) |
| `user@mailinator.com` | Blocked (disposable) |

---

## 7. Deployment Plan

### 7.1 Pre-Deployment Checklist

- [ ] Add `USERCHECK_API_TOKEN` to production environment
- [ ] Verify all `USERCHECK_BLOCK_*` settings are configured as desired
- [ ] Test API connectivity from production environment
- [ ] Set up monitoring alerts for API failures

### 7.2 Deployment Steps

1. **Deploy Phase 1** (Backend services)
   - Zero downtime deploy
   - Validation not yet active (feature flag off)
   
2. **Deploy Phase 2** (Validation rules)
   - Enable validation for new registrations
   - Monitor for API failures
   
3. **Deploy Phase 3-4** (Async validation & Frontend)
   - Improves UX, no backend changes
   
4. **Deploy Phase 5** (Monitoring)
   - Ensure alerts are working

### 7.3 Rollback Plan

If issues occur:
1. Set `USERCHECK_ENABLED=false` in environment
2. Restart application
3. Validation is bypassed immediately (emergency only!)

**Note:** Disabling validation reduces security. Monitor closely.

---

## 8. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| UserCheck API downtime | **Users CAN'T register** | Low | **FAIL CLOSED** - This is intentional for security |
| Rate limiting (429) | Users temporarily blocked | Medium | Display retry message, log for scaling discussion |
| False positives (legit emails blocked) | User frustration | Low | Each check is independently configurable via .env |
| API token compromise | Unauthorized API usage | Low | Store in env, rotate if needed |
| Slow API response | Poor UX | Low | 5s timeout with clear error message |

---

## Appendix A: API Response Examples

### Valid Email (Gmail)
```json
{
  "status": 200,
  "email": "user@gmail.com",
  "disposable": false,
  "public_domain": true,
  "role_account": false,
  "blocklisted": false,
  "spam": false
}
```

### Disposable Email
```json
{
  "status": 200,
  "email": "test@mailinator.com",
  "disposable": true,
  "disposable_provider": "mailinator.com",
  "public_domain": false,
  "role_account": false,
  "blocklisted": false,
  "spam": false
}
```

### Role Account
```json
{
  "status": 200,
  "email": "admin@company.com",
  "disposable": false,
  "public_domain": false,
  "role_account": true,
  "blocklisted": false,
  "spam": false
}
```

### Typo Detected
```json
{
  "status": 200,
  "email": "user@gmal.com",
  "did_you_mean": "user@gmail.com",
  "disposable": false,
  "public_domain": false
}
```

---

## Appendix B: Estimated Timeline

| Phase | Duration | Cumulative |
|-------|----------|------------|
| Phase 1: Core Service | 2 days | 2 days |
| Phase 2: Validation Rules | 2 days | 4 days |
| Phase 3: Async API | 2 days | 6 days |
| Phase 4: Frontend | 2 days | 8 days |
| Phase 5: Monitoring | 1 day | 9 days |
| **Total** | **9 days** | |

**Note:** Phases 1-2 are required for MVP. Phases 3-5 are enhancements.

---

## Approval

| Role | Name | Signature | Date |
|------|------|-----------|------|
| Technical Lead | | | |
| Product Owner | | | |
| Security Review | | | |

---

**Next Steps:**
1. Review this plan with stakeholders
2. Approve phases for implementation
3. Create detailed tickets for Phase 1
4. Schedule implementation sprint
