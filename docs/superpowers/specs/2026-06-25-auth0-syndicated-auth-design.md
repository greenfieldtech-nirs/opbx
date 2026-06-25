# Auth0 Syndicated Signup/Login for OPBX SaaS Mode

> **Status**: Design approved, ready for implementation planning  
> **Date**: 2026-06-25  
> **Approach**: B — Auth0 with account linking and request-to-join existing organizations  
> **Authors**: Engineering Team  

---

## 1. Objective

Enable syndicated signup and login into OPBX via [Auth0](https://auth0.com/) when the application is running in SaaS mode (`OPBX_SAAS_ENABLED=true`). The feature adds Auth0 social-provider authentication **alongside** the existing username/password registration and login workflow — it does not replace it.

Supported identity providers (IdPs): **Google, Facebook, Microsoft, GitHub, X**.

---

## 2. Goals & Non-Goals

### 2.1 Goals

- Allow new users to sign up for OPBX using an Auth0 social identity provider.
- Allow returning users to log in with their linked Auth0 identity.
- Support two post-authentication paths for new Auth0 users:
  1. **Create a new organization** (mirrors current email/password registration, user becomes OWNER).
  2. **Request to join an existing organization** (requires owner/admin approval).
- Allow existing password-based users to link one or more Auth0 providers to their account.
- Keep existing username/password registration, login, password reset, and RBAC unchanged.
- Gate the entire feature behind a single SaaS flag and Auth0 environment configuration.

### 2.2 Non-Goals

- Removing or deprecating the existing username/password flow.
- Acting as a generic OAuth2/SAML identity provider broker beyond Auth0.
- Admin UI for configuring multiple Auth0 tenants/applications.
- Automatic migration of existing password users to Auth0.
- Advanced Auth0 features: MFA, Auth0 Organizations, custom rules/actions, enterprise connections.
- Billing/subscription integration during signup.

---

## 3. Definitions

| Term | Definition |
|------|------------|
| **SaaS mode** | OPBX deployment mode enabled by `OPBX_SAAS_ENABLED=true`. In this mode, the application exposes public signup flows including Auth0. |
| **Auth0 connection** | An Auth0 social identity provider configuration. Each supported IdP maps to an Auth0 connection named `google-oauth2`, `facebook`, `windowslive`, `github`, `twitter`, etc. |
| **Provider key** | Internal OPBX identifier for a social provider: `google`, `facebook`, `microsoft`, `github`, `x`. |
| **Provider subject** | The opaque identifier returned by Auth0 in the `sub` claim (e.g. `google-oauth2|123456`). |
| **Social identity** | A row in `user_social_identities` that links an OPBX `User` to a provider subject. |
| **Onboarding intent** | The user's declared goal during first-time Auth0 authentication: `create_organization` or `join_organization`. |
| **Join request** | A pending record in `organization_join_requests` created when a new Auth0 user asks to join an existing organization. |

---

## 4. Existing System Context

### 4.1 Backend

- **AuthController** (`app/Http/Controllers/Api/AuthController.php`): Handles login, logout, refresh, `/me`.
- **RegisterController** (`app/Http/Controllers/Api/RegisterController.php`): Creates `Organization` + `User` (OWNER) inside a transaction and issues a Sanctum token.
- **User model** (`app/Models/User.php`): Uses `OrganizationScope` global scope; `email` is unique.
- **ApplicationConfig** (`app/Services/ApplicationConfig.php`): Reads `OPBX_APPLICATION_MODE` and `OPBX_APPLICATION_WEBHOOK_BASEURL`.
- **ConfigurationController** (`app/Http/Controllers/Api/ConfigurationController.php`): Exposes app-level config to frontend.

### 4.2 Frontend

- **AuthContext** (`frontend/src/context/AuthContext.tsx`): Stores token/user in localStorage, provides `login`, `register`, `logout`, `refreshUser`.
- **Login / Register pages** (`frontend/src/pages/Login.tsx`, `frontend/src/pages/Register.tsx`): Email/password forms.
- **ConfigContext** (`frontend/src/context/ConfigContext.tsx`): Consumes `/v1/config` and exposes `isProduction`, `shouldHideWebhookFields`, etc.
- **API service** (`frontend/src/services/api.ts`): Axios with Bearer token interceptor.

### 4.3 Constraints

- No existing OAuth/Socialite package installed.
- No existing invitation or join-request system.
- Multi-tenancy: every user belongs to exactly one `organization_id`; models use `OrganizationScope`.
- Sanctum tokens are scoped by role abilities.

---

## 5. Proposed Approach (Approach B)

Auth0 acts as the identity broker. OPBX stores only the Auth0 `sub` and normalized profile data in a separate `user_social_identities` table. Password-based users can link one or more Auth0 providers. New Auth0 users either create a new organization or submit a join request to an existing one.

---

## 6. Configuration

### 6.1 New Environment Variables

```bash
# Feature flag
OPBX_SAAS_ENABLED=true

# Auth0 tenant configuration
AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=...
AUTH0_CLIENT_SECRET=...
AUTH0_REDIRECT_URI=https://app.opbx.com/ui/auth/callback

# Comma-separated list of enabled providers. Each value must match an Auth0 connection name mapping.
AUTH0_PROVIDERS=google,facebook,microsoft,github,x
```

### 6.2 Provider-to-Connection Mapping

OPBX provider keys map to Auth0 connection names as follows:

| OPBX Provider | Auth0 Connection | Notes |
|---------------|------------------|-------|
| `google` | `google-oauth2` | Standard Auth0 Google social connection. |
| `facebook` | `facebook` | Standard Auth0 Facebook social connection. |
| `microsoft` | `windowslive` | Microsoft Account (Live) connection. |
| `github` | `github` | Standard Auth0 GitHub social connection. |
| `x` | `twitter` | Standard Auth0 X/Twitter social connection. |

The mapping is defined in config (`config/services.php` or a dedicated Auth0 config) and is not user-configurable in v1.

### 6.3 Runtime Feature Detection

`ApplicationConfig::getConfigurationSummary()` will include:

```json
{
  "saas_enabled": true,
  "auth0": {
    "enabled": true,
    "domain": "your-tenant.us.auth0.com",
    "client_id": "...",
    "providers": ["google", "facebook", "microsoft", "github", "x"]
  }
}
```

The `client_secret` is never exposed.

---

## 7. Data Model

### 7.1 `user_social_identities`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `user_id` | bigint unsigned FK → users.id | Cascading delete. |
| `provider` | varchar(32) | One of `google`, `facebook`, `microsoft`, `github`, `x`. |
| `provider_subject` | varchar(255) | Auth0 `sub` claim. |
| `provider_email` | varchar(255) | Normalized email from Auth0 profile. |
| `provider_data` | json | Raw normalized profile snapshot (name, picture, etc.). |
| `created_at` / `updated_at` | timestamp | |

**Constraints**: Unique composite index on `(provider, provider_subject)`. Unique composite index on `(user_id, provider)` to allow one subject per provider per user.

### 7.2 `organization_join_requests`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `organization_id` | bigint unsigned FK → organizations.id | |
| `email` | varchar(255) | From Auth0 profile. |
| `name` | varchar(255) | From Auth0 profile. |
| `provider` | varchar(32) | Provider used. |
| `provider_subject` | varchar(255) | Auth0 `sub`. |
| `status` | varchar(32) | `pending`, `approved`, `rejected`. |
| `role` | varchar(32) | Default `pbx_user`. |
| `created_at` / `updated_at` / `deleted_at` | timestamp | Soft deletes. |

**Constraints**: Index on `(organization_id, status)`.

### 7.3 `users` Table

No schema changes. The `email` column remains unique. Auth0 linking is handled through `user_social_identities`.

---

## 8. Backend API

### 8.1 Public Endpoints (no auth required)

#### `GET /v1/config`

Extends existing response to include SaaS/Auth0 settings.

**Response**:

```json
{
  "mode": "production",
  "is_production": true,
  "saas_enabled": true,
  "auth0": {
    "enabled": true,
    "domain": "your-tenant.us.auth0.com",
    "client_id": "...",
    "providers": ["google", "facebook", "microsoft", "github", "x"]
  }
}
```

#### `POST /v1/auth/auth0/redirect`

Generates the Auth0 `/authorize` URL and a signed state parameter.

**Request body**:

```json
{
  "provider": "google",
  "intent": "login"
}
```

`intent` values: `login`, `register`.

**Response**:

```json
{
  "redirect_url": "https://your-tenant.us.auth0.com/authorize?...",
  "state": "random-state-value"
}
```

State is stored server-side (cache or signed cookie) with a 10-minute TTL and includes `provider`, `intent`, and a nonce.

#### `GET /v1/auth/auth0/callback`

Handles the Auth0 callback. Validates `state`, exchanges `code` for tokens, fetches `/userinfo`, resolves the OPBX user.

**Query parameters**:

- `code` — Authorization code from Auth0.
- `state` — State returned by Auth0.

**Resolution outcomes**:

| Scenario | Response |
|----------|----------|
| Existing social identity | Issue Sanctum token; return user/org. |
| Existing password user with same email, intent=login | Return `AUTH0_ACCOUNT_EXISTS` error with option to link. |
| New email, intent=register | Create `Organization` + `User` (OWNER) + `user_social_identity`; issue token. |
| New email, intent=login | Return `AUTH0_REGISTRATION_REQUIRED` error; frontend prompts onboarding. |
| New email, intent=join (with `organization_id` or slug) | Create `organization_join_request` with status `pending`; return `JOIN_REQUEST_PENDING`. |
| Auth0 email unverified | Return `AUTH0_EMAIL_UNVERIFIED` error. |

**Success response**:

```json
{
  "user": { "id": 1, "name": "...", "email": "...", "role": "owner", "status": "active" },
  "organization": { "id": 1, "name": "...", "slug": "..." },
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 86400
}
```

### 8.2 Authenticated Endpoints

#### `POST /v1/auth/auth0/link`

Link the current authenticated user's account to an Auth0 identity.

**Flow**:

1. Generate Auth0 `/authorize` URL with `intent=link` and a nonce bound to the current user.
2. After Auth0 callback, verify the user is authenticated, validate email match OR require current password.
3. Create `user_social_identity` row.

For v1, we implement the simpler **email-match** rule: the Auth0 profile email must match the logged-in user's email and `email_verified=true`.

#### `POST /v1/auth/auth0/unlink`

Remove a linked identity by provider.

**Request body**:

```json
{ "provider": "google" }
```

A user cannot unlink their last identity if they have no password set. (Out of scope for v1; users created via Auth0 have no password.)

#### `POST /v1/organizations/join-requests`

Submit a join request after Auth0 authentication when the user has no organization yet.

**Request body**:

```json
{
  "organization_slug": "acme-corp",
  "provider": "google",
  "provider_subject": "google-oauth2|123456",
  "name": "Jane Doe",
  "email": "jane@example.com"
}
```

Response: 201 with the created request.

#### `GET /v1/organizations/join-requests`

List pending join requests for the current user's organization. Owner/admin only.

#### `POST /v1/organizations/join-requests/{id}/approve`

Approve a pending request. Creates a new `User` with role `pbx_user` under the organization, creates the corresponding `user_social_identity`, marks request `approved`. Owner/admin only.

#### `POST /v1/organizations/join-requests/{id}/reject`

Mark request `rejected`. Owner/admin only.

---

## 9. Frontend

### 9.1 Login & Register Pages

When `config.saas_enabled && config.auth0.enabled` is true, render provider buttons below the email/password form.

```tsx
<div className="relative my-4">
  <div className="absolute inset-0 flex items-center"><span className="w-full border-t" /></div>
  <div className="relative flex justify-center text-xs uppercase">
    <span className="bg-background px-2 text-muted-foreground">Or continue with</span>
  </div>
</div>
<div className="grid grid-cols-2 gap-3">
  <Button variant="outline" onClick={() => initiateAuth0('google')}>
    <GoogleIcon className="mr-2 h-4 w-4" /> Google
  </Button>
  {/* ... Facebook, Microsoft, GitHub, X */}
</div>
```

Clicking a provider button calls `POST /v1/auth/auth0/redirect` with `provider` and `intent` (`login` on Login page, `register` on Register page), then redirects the browser to the returned `redirect_url`.

### 9.2 Auth0 Callback Page

New route: `/ui/auth/callback`.

On mount, extracts `code` and `state` from URL and calls `GET /v1/auth/auth0/callback`.

Handles outcomes:

| Outcome | Action |
|---------|--------|
| Success | Store token/user, redirect to `/ui/dashboard`. |
| `AUTH0_REGISTRATION_REQUIRED` | Redirect to `/ui/auth/onboarding?email=...&provider=...&subject=...`. |
| `AUTH0_ACCOUNT_EXISTS` | Show message: "An account with this email already exists. Please log in with your password, then link this account in Profile settings." |
| `JOIN_REQUEST_PENDING` | Show pending approval screen. |
| `AUTH0_EMAIL_UNVERIFIED` | Show error, advise user to verify email with provider. |
| Generic error | Show error toast, redirect to login. |

### 9.3 Onboarding Page

New route: `/ui/auth/onboarding`.

Presents two options to a first-time Auth0 user:

1. **Create a new organization** — calls callback with `intent=register` and existing Auth0 data.
2. **Request to join an existing organization** — shows form asking for `organization_slug`, then calls `POST /v1/organizations/join-requests`.

### 9.4 Profile / Linked Accounts

Add a "Linked Accounts" card to the Profile page showing connected providers and a "Connect" button for unlinked providers. Uses `/v1/auth/auth0/link`.

### 9.5 AuthContext Updates

Add:

- `initiateAuth0Login(provider: string, intent: string): Promise<string>`
- `handleAuth0Callback(code: string, state: string): Promise<AuthResult>`
- `linkAuth0Identity(provider: string): Promise<void>`
- `unlinkAuth0Identity(provider: string): Promise<void>`

---

## 10. Security

### 10.1 State Parameter

- The `state` parameter must be a cryptographically secure random value.
- It is stored server-side (Redis cache key `auth0:state:{state}`) with a 10-minute TTL.
- On callback, the state is looked up and deleted (one-time use).
- State payload includes `provider`, `intent`, `nonce`, and (for link) `user_id`.

### 10.2 PKCE

Auth0 Universal Login supports PKCE. Since OPBX is a public SPA, the redirect endpoint should use PKCE. The backend will generate and store the code verifier with the state.

### 10.3 Token Exchange

Authorization code is exchanged server-side using `AUTH0_CLIENT_SECRET`. Tokens are never exposed to the frontend except for the final OPBX Sanctum token.

### 10.4 Email Verification

- New accounts require `email_verified=true` from Auth0.
- Linking existing accounts requires `email_verified=true` and email match.

### 10.5 Account Takeover Prevention

- Linking an Auth0 identity to an existing user requires the user to be authenticated.
- If the Auth0 email differs from the logged-in user's email, the link is rejected in v1.
- Social identities are unique by `(provider, provider_subject)`.

### 10.6 Rate Limiting

- `POST /v1/auth/auth0/redirect`: throttle by IP, 10 attempts per minute.
- `GET /v1/auth/auth0/callback`: throttle by IP, 20 attempts per minute.
- Join request endpoints: throttle by IP, 5 per minute.

---

## 11. Error Handling

### 11.1 Backend Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `AUTH0_NOT_CONFIGURED` | 503 | SaaS/Auth0 not enabled or misconfigured. |
| `AUTH0_INVALID_STATE` | 400 | State mismatch or expired. |
| `AUTH0_CODE_EXCHANGE_FAILED` | 400 | Token exchange failed (invalid code, etc.). |
| `AUTH0_EMAIL_UNVERIFIED` | 422 | Auth0 profile email not verified. |
| `AUTH0_REGISTRATION_REQUIRED` | 409 | New Auth0 user must complete onboarding. |
| `AUTH0_ACCOUNT_EXISTS` | 409 | Email already registered with password. |
| `JOIN_REQUEST_PENDING` | 202 | Join request submitted, pending approval. |
| `ORGANIZATION_NOT_FOUND` | 404 | Slug does not match an active organization. |

### 11.2 Frontend Error Handling

- All Auth0 errors show user-friendly messages via `toast`.
- Callback errors redirect to `/ui/login` with an error query parameter.

---

## 12. Testing Strategy

### 12.1 Backend Tests

- **Feature tests**:
  - Auth0 redirect URL generation includes valid state and PKCE.
  - Callback with existing social identity issues Sanctum token.
  - Callback with new email + register intent creates organization, user, and identity.
  - Callback with unverified email returns `AUTH0_EMAIL_UNVERIFIED`.
  - Callback with existing password email returns `AUTH0_ACCOUNT_EXISTS`.
  - Join request creation, listing, approval, rejection.
  - Account linking by authenticated user with matching email.
  - Account unlinking.
- **Unit tests**:
  - `ApplicationConfig` includes Auth0 settings only when enabled.
  - Auth0 service normalizes profile data.
  - State store create/validate/delete.
- **Mocking**:
  - Mock Auth0 `/oauth/token` and `/userinfo` endpoints using Laravel HTTP fake.

### 12.2 Frontend Tests

- Provider buttons render only when SaaS enabled.
- Callback page handles each backend outcome.
- Onboarding page submits correct intent/request.

### 12.3 Manual/Integration Tests

- Configure Auth0 test application with all five providers.
- Walk through signup, login, join request, approval, and linking flows end-to-end.

---

## 13. Open Questions / Future Work

1. **Account linking for mismatched emails**: v1 rejects. Future: allow linking after password re-authentication.
2. **Unlinking last identity**: v1 allows unlinking only if user has a password. Users created via Auth0 cannot unlink their only identity.
3. **Auth0 Organizations**: Evaluate if Auth0's tenant-level organization feature should replace OPBX join requests in the future.
4. **Email change at provider**: If a user changes their Google email, OPBX email remains unchanged until manually updated.

---

## 14. Related Files & Modules

- `app/Services/ApplicationConfig.php`
- `app/Http/Controllers/Api/ConfigurationController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/RegisterController.php`
- `app/Models/User.php`
- `frontend/src/context/AuthContext.tsx`
- `frontend/src/context/ConfigContext.tsx`
- `frontend/src/pages/Login.tsx`
- `frontend/src/pages/Register.tsx`
- `frontend/src/services/config.service.ts`

---

## 15. Acceptance Criteria

- [ ] When `OPBX_SAAS_ENABLED=false`, Login/Register pages show no Auth0 buttons and Auth0 endpoints return 503.
- [ ] When `OPBX_SAAS_ENABLED=true` and Auth0 is configured, Login/Register pages show Google, Facebook, Microsoft, GitHub, and X buttons.
- [ ] A new user can sign up via Google and create a new organization; they are OWNER.
- [ ] A returning user can log in via Google and receive a Sanctum token.
- [ ] A new user can request to join an existing organization; owners/admins see the request and can approve/reject.
- [ ] An existing password user with the same email can link their Google identity and log in with either method.
- [ ] All new code has feature/unit tests and passes `./run-tests.sh --filter=Auth0`.
- [ ] PHP code passes `vendor/bin/pint`.
- [ ] Frontend code passes `npm run type-check` and `npm run lint`.
