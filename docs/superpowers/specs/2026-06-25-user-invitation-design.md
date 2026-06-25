# User Invitation via Auth0-Bound Magic Link

> **Status**: Design approved — ready for implementation plan
> **Date**: 2026-06-25
> **Module**: User Management, Authentication & Authorization

---

## 1. Goal

Add an **Invite User** button to the Users management page that lets an Owner or PBX Admin invite a new person to the current organization by email. The invited user receives an OPBX-branded email with a secure magic link. Clicking the link binds the user to an Auth0 identity (password or social) and activates their account.

---

## 2. Out of Scope

- No support for inviting users into roles other than `pbx_user`.
- No invitation to join multiple organizations with the same email in v1.
- No in-app notification center; duplicate-invite notifications are sent by email only.

---

## 3. Data Model

### 3.1 `users` table

Reuse the existing `status` column. Add a new enum case:

- `pending` — User was invited but has not yet accepted and authenticated via Auth0.

Existing enum cases:
- `active`
- `inactive`

The `password` column may be `NULL` for pending users.

### 3.2 Redis

Single-use invitation tokens:

- Key: `invite:{token_hash}`
- Value: JSON `{ "user_id": 123, "organization_id": 456, "email": "user@example.com" }`
- TTL: `OPBX_INVITE_TOKEN_TTL_HOURS` × 3600 seconds (default 24 hours)

A SHA-256 hash of the raw token is stored; the raw token is only present in the email link.

---

## 4. Backend API

### 4.1 Invite a user

```http
POST /api/v1/users/invite
Authorization: Bearer <sanctum-token>
Content-Type: application/json

{
  "email": "new.user@example.com"
}
```

**Authorization**: Owner or PBX Admin of the current organization.

**Behavior**:
1. Validate email format and domain rules.
2. Check if any user with this email already exists in the current organization. If yes:
   - Return `422 Unprocessable Entity` with code `USER_ALREADY_EXISTS`.
   - Dispatch a notification email to all platform managers (`is_platform_manager = true`) alerting them of the duplicate-invite attempt.
3. Create a new `User`:
   - `organization_id` = current user's organization
   - `name` = local part of email (placeholder, editable later)
   - `email` = provided email
   - `role` = `pbx_user`
   - `status` = `pending`
   - `password` = `null`
4. Generate a cryptographically random token (32 bytes, base64url).
5. Store hash in Redis with TTL.
6. Send invitation email via `TransactionalEmailService` with magic link.
7. Return `201 Created` with the created user resource and `invite_sent: true`.

**Rate limiting**: 10 invites per organization per hour (configurable via `OPBX_INVITE_RATE_LIMIT_PER_HOUR`, default 10).

### 4.2 Validate invitation token

```http
GET /api/v1/users/invite/validate?token=<token>
```

**Behavior**:
- Look up token hash in Redis.
- Return user preview: `email`, `organization_name`.
- Do **not** consume the token.
- If invalid/expired, return `410 Gone` with code `INVITE_EXPIRED_OR_INVALID`.

### 4.3 Accept invitation (consume token and start Auth0 flow)

```http
POST /api/v1/users/invite/accept
Content-Type: application/json

{
  "token": "<token>"
}
```

**Behavior**:
1. Validate token, fetch pending user.
2. Atomically consume token (delete from Redis).
3. Create an Auth0 OAuth state with intent `invitation` and the invited user's ID.
4. Return Auth0 authorize URL (same as login/signup but with the email hint and intent attached).

The frontend redirects the browser to the returned URL.

### 4.4 Auth0 callback binding

Extend `Auth0AccountResolver` so that when the Auth0 callback has intent `invitation`:

1. Look up the pending user by the user ID in state.
2. Verify the Auth0 profile email matches the pending user's email and `email_verified = true`.
3. If matched:
   - Set `status = active`.
   - Create a `UserSocialIdentity` record for the Auth0 provider/subject.
   - Issue Sanctum session/token.
   - Redirect to Dashboard.
4. If email mismatch or unverified:
   - Redirect to an error page (`/ui/auth/error?reason=invite_email_mismatch`).

---

## 5. Frontend

### 5.1 Users page

File: `frontend/src/pages/UsersComplete.tsx`

- Add **Invite User** button next to **Add User**.
- Only visible to users who can create users (Owner, PBX Admin).
- Open a dialog with a single email input and submit button.
- On success, show toast and refresh the user list.
- On `USER_ALREADY_EXISTS` error, display the returned message.

### 5.2 Invitation dialog

File: `frontend/src/components/Users/InviteUserDialog.tsx` (new)

- Email field with validation.
- Submit button with loading state.
- Success/error toasts.

### 5.3 Accept invitation page

File: `frontend/src/pages/AcceptInvitation.tsx` (new)
- Route: `/ui/invite?token=<token>`
- On mount, validate token via `GET /api/v1/users/invite/validate`.
- Show organization name and invited email.
- Button **Accept & Continue** calls `POST /api/v1/users/invite/accept` and redirects to the returned Auth0 URL.
- Handle expired/invalid token with an error message and a link to request a new invite.

### 5.4 Auth0 callback

File: `frontend/src/pages/Auth0Callback.tsx`

- Already handles `intent` from OAuth state.
- Add handling for `intent=invitation` to finalize activation and redirect to Dashboard.

---

## 6. Email Template

New Blade template: `resources/views/emails/user-invitation.blade.php`

Includes:
- Subject: "You've been invited to join {{ organization_name }} on OPBX"
- Plain text and HTML versions.
- Magic link: `{{ config('app.frontend_url') }}/ui/invite?token={{ $token }}`
- Expiry note (e.g., "This link expires in 24 hours").
- Sent from the existing transactional email service driver.

---

## 7. Security

- Tokens are single-use and expire after TTL.
- Raw token is never stored; only its SHA-256 hash is kept in Redis.
- Pending users cannot log in with password (no password hash).
- Rate limiting per organization prevents invite spam.
- Auth0 identity binding requires a verified email match.
- All invite endpoints use Sanctum authentication except validation/accept which are public but token-protected.

---

## 8. Configuration

Add to `.env.example`:

```bash
# User invitations
OPBX_INVITE_TOKEN_TTL_HOURS=24
OPBX_INVITE_RATE_LIMIT_PER_HOUR=10
```

Expose through `config/opbx.php` or reuse `config/services.php`.

---

## 9. Testing

### Backend
- Feature test: Owner invites a new user → user created as pending, token stored, email queued.
- Feature test: Duplicate invite to same org → 422 and platform manager email dispatched.
- Feature test: Accept invitation with valid token → Auth0 state created.
- Feature test: Auth0 callback with intent=invitation activates user and links identity.
- Feature test: Expired/invalid token returns 410.
- Feature test: Rate limiting blocks excessive invites.

### Frontend
- Type-check and build.
- Component test for invite dialog validation.

---

## 10. Files to Modify / Create

### Backend
- `app/Enums/UserStatus.php` — add `PENDING`
- `app/Models/User.php` — add `isPending()` helper, allow nullable password
- `app/Http/Controllers/Api/UserInvitationController.php` — new
- `app/Http/Requests/User/InviteUserRequest.php` — new
- `app/Services/UserInvitation/UserInvitationService.php` — new
- `app/Services/Auth0/Auth0AccountResolver.php` — handle `invitation` intent
- `routes/api.php` — add invite routes
- `resources/views/emails/user-invitation.blade.php` — new
- `.env.example` — add config keys
- `config/services.php` or new `config/opbx.php` — config keys

### Frontend
- `frontend/src/pages/UsersComplete.tsx` — add Invite User button
- `frontend/src/components/Users/InviteUserDialog.tsx` — new
- `frontend/src/pages/AcceptInvitation.tsx` — new
- `frontend/src/router.tsx` — add `/ui/invite` route
- `frontend/src/services/users.service.ts` or new `invitation.service.ts` — API calls

---

## 11. Dependencies

- Existing `TransactionalEmailService`
- Existing `Auth0Service` and `Auth0StateStore`
- Existing Redis cache
- Existing `UserPolicy` authorization patterns

---

## 12. Open Questions Resolved

| Question | Decision |
|----------|----------|
| Default role | Always `pbx_user` |
| Token expiry | 24 hours, configurable via `.env` |
| Email template | New `emails.user-invitation` Blade template |
| Duplicate email | Return 422 + notify platform managers by email |
| Post-accept redirect | Dashboard |
