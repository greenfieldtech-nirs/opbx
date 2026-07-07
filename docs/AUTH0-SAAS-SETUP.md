# Auth0 SaaS Setup Guide

This guide explains how to configure Auth0 for OPBX syndicated social authentication in SaaS mode.

## Prerequisites

- An Auth0 tenant (free tier works)
- OPBX deployed with HTTPS (Auth0 requires TLS redirect URIs)
- `OPBX_SAAS_ENABLED=true` in `.env`

## 1. Create an Auth0 Application

1. Log in to the [Auth0 Dashboard](https://manage.auth0.com/).
2. Go to **Applications > Applications** and click **Create Application**.
3. Choose **Regular Web Application**.
4. Note the **Domain**, **Client ID**, and **Client Secret**.

## 2. Configure Application URLs

In the Auth0 application settings:

- **Allowed Callback URLs**: `https://your-opbx-domain/api/v1/auth/auth0/callback`
- **Allowed Logout URLs**: `https://your-opbx-domain/ui/login`
- **Allowed Web Origins**: `https://your-opbx-domain`
- **Allowed Origins (CORS)**: `https://your-opbx-domain`

Replace `your-opbx-domain` with your actual domain.

## 3. Enable Social Connections

1. Go to **Authentication > Social**.
2. Enable the providers you want to support: Google, Facebook, Microsoft, GitHub, X.
3. Configure each provider's API keys/secrets as required by Auth0.
4. For each enabled connection, set `email_verified` behavior:
   - **Google / Microsoft**: typically verified by default.
   - **Facebook / GitHub / X**: verify that the connection returns `email_verified: true` for verified email addresses.

## 4. Configure OPBX Environment

Add the following to your OPBX `.env`:

```bash
OPBX_SAAS_ENABLED=true

AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=your-client-id
AUTH0_CLIENT_SECRET=your-client-secret
AUTH0_REDIRECT_URI=https://your-opbx-domain/api/v1/auth/auth0/callback
AUTH0_PROVIDERS=google,facebook,microsoft,github,x
```

The `AUTH0_PROVIDERS` list controls which provider buttons appear on the login and register pages.

## 5. Clear Config Cache

```bash
docker compose exec app php artisan config:clear
```

## 6. Verify Frontend Config

Open the browser dev tools and check the `/api/v1/config` response. It should include:

```json
{
  "saas_enabled": true,
  "auth0": {
    "enabled": true,
    "domain": "your-tenant.us.auth0.com",
    "client_id": "your-client-id",
    "redirect_uri": "https://your-opbx-domain/api/v1/auth/auth0/callback",
    "providers": ["google", "facebook", "microsoft", "github", "x"]
  }
}
```

If `auth0.enabled` is `false`, verify that all required `AUTH0_*` variables are set and the config cache is cleared.

## User Flows

### New User — Create Organization

1. Clicks a social provider button on `/ui/register`.
2. Authenticates with Auth0 and returns to `/ui/auth/callback`.
3. Backend creates a new organization and user with role **Owner**.
4. User is logged in and redirected to the dashboard.

### New User — Request to Join Existing Organization

1. Clicks a social provider button on `/ui/register`.
2. After Auth0 callback, the onboarding page offers **Request to join an organization**.
3. User enters the target organization name.
4. The owner or an admin of that organization approves the request in **Profile > Pending Join Requests**.
5. User receives a session token and can log in.

### Existing Password User — Link Auth0 Identity

1. Log in with email and password.
2. Go to **Profile > Linked Accounts**.
3. Click **Connect** next to a provider.
4. Complete Auth0 authorization with the **same verified email**.
5. The identity is linked; future logins can use the social provider.

### Existing Social User — Direct Login

1. Clicks the same social provider button on `/ui/login`.
2. Backend matches the Auth0 subject in `user_social_identities`.
3. User is logged in and redirected to the dashboard.

## Security Notes

- OAuth `state` and PKCE `code_verifier` are stored server-side in Redis/Cache with a 10-minute TTL.
- The Auth0 client secret is never sent to the frontend.
- Account linking requires the Auth0 email to match the logged-in user's email and `email_verified=true`.
- New users created via Auth0 receive a random hashed password and cannot log in via email/password until they set a password through a future password-reset flow.

## Troubleshooting

### "Auth0 is not configured" error

- Verify all `AUTH0_*` environment variables are set.
- Run `php artisan config:clear`.

### "Invalid state" error

- The OAuth state expired (10-minute TTL) or the callback URL was reused.
- Try logging in again.

### "Email not verified" error

- The social provider did not return `email_verified=true`.
- Verify the email in the Auth0/social provider account.

### Provider button does not appear

- Check that the provider is included in `AUTH0_PROVIDERS`.
- Check that the provider is enabled in Auth0 **Authentication > Social**.

## Related Documentation

- [Auth0 Syndicated Auth Design Spec](../docs/superpowers/specs/2026-06-25-auth0-syndicated-auth-design.md)
- [Auth0 Syndicated Auth Implementation Plan](../docs/superpowers/plans/2026-06-25-auth0-syndicated-auth-implementation-plan.md)
