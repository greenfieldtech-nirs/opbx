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

- **Allowed Callback URLs**: `https://your-opbx-domain/ui/auth/callback`

The callback is the frontend route `/ui/auth/callback`. That page sends the `code` and `state` to the backend (`GET /api/v1/auth/auth0/callback`).
- **Allowed Logout URLs**: `https://your-opbx-domain/ui/login`
- **Allowed Web Origins**: `https://your-opbx-domain`
- **Allowed Origins (CORS)**: `https://your-opbx-domain`

Replace `your-opbx-domain` with your actual domain.

## 3. Enable Social Connections

1. Go to **Authentication > Social**.
2. Enable the providers you want to support: Google and/or GitHub (the only providers OPBX supports).
3. Configure each provider's API keys/secrets as required by Auth0.
4. For each enabled connection, set `email_verified` behavior:
   - **Google**: typically verified by default.
   - **GitHub**: verify that the connection returns `email_verified: true` for verified email addresses.

## 4. Configure OPBX Environment

Add the following to your OPBX `.env`:

```bash
OPBX_SAAS_ENABLED=true

AUTH0_DOMAIN=your-tenant.us.auth0.com
AUTH0_CLIENT_ID=your-client-id
AUTH0_CLIENT_SECRET=your-client-secret
AUTH0_REDIRECT_URI=https://your-opbx-domain/ui/auth/callback
AUTH0_PROVIDERS=google,github
```

`AUTH0_PROVIDERS` lists the enabled providers: `google` and/or `github`. Each one gets a button on the login and register pages (`frontend/src/components/Auth/SocialAuthButtons.tsx`) and on the profile's Linked Accounts card. Other values (`facebook`, `microsoft`, `x`, ...) are ignored, and the API refuses them with 422.

## 5. Reload the Configuration

Recreate the PHP containers so they read the new `.env` values (the entrypoint clears the config cache on start):

```bash
docker compose up -d --force-recreate app queue-worker queue-worker-long scheduler
```

## 6. Verify Frontend Config

Open the browser dev tools and check the `/api/v1/config/application` response. It should include:

```json
{
  "saas_enabled": true,
  "auth0": {
    "enabled": true,
    "domain": "your-tenant.us.auth0.com",
    "client_id": "your-client-id",
    "providers": ["google", "github"]
  }
}
```

`saas_enabled` and `auth0.enabled` both follow `OPBX_SAAS_ENABLED` alone. If they are `false`, verify `OPBX_SAAS_ENABLED=true` and that the containers were recreated. Empty `AUTH0_DOMAIN`, `AUTH0_CLIENT_ID`, `AUTH0_CLIENT_SECRET` or `AUTH0_REDIRECT_URI` does not change this flag; the Auth0 endpoints then fail with "Auth0 is enabled but missing required configuration."

## User Flows

### New User — Create Organization

1. Clicks a social provider button on `/ui/register`.
2. Authenticates with Auth0 and returns to `/ui/auth/callback`.
3. Backend creates a new organization (named `<email> Organization`, time zone UTC) and a user with role **Owner**.
4. User is logged in and redirected to the dashboard.

### New User — Request to Join Existing Organization

1. Clicks a social provider button on `/ui/login` with an email that has no OPBX account. (From `/ui/register` the backend always creates a new organization.)
2. After the Auth0 callback, the **Complete your account** page (`/ui/auth/onboarding`) opens. The onboarding session lasts 60 minutes and can be used once.
3. User enters the target organization's **Organization slug** (for example `acme-corp`) and clicks **Request to join organization**.
4. An Owner or PBX Admin of that organization approves or rejects the request. There is no screen for this yet; use the API (`GET /api/v1/organizations/join-requests`, then `POST .../{id}/approve` or `.../{id}/reject`).
5. Approval creates an active **PBX User** linked to that social account. The user then signs in with the same provider. No email is sent about the decision.

The same page also offers **Create a new organization**, which restarts the provider sign-in with the register intent.

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
- New users created via Auth0 receive a random hashed password, so they cannot log in with email and password until a password is set for them.

## Troubleshooting

### `AUTH0_NOT_CONFIGURED` ("Auth0 is not enabled.") error

- Set `OPBX_SAAS_ENABLED=true` and recreate the PHP containers (see step 5).

### "Auth0 is enabled but missing required configuration."

- Set `AUTH0_DOMAIN`, `AUTH0_CLIENT_ID`, `AUTH0_CLIENT_SECRET` and `AUTH0_REDIRECT_URI`, then recreate the PHP containers.

### "Invalid state" error

- The OAuth state expired (10-minute TTL) or the callback URL was reused.
- Try logging in again.

### `AUTH0_EMAIL_UNVERIFIED` ("Please verify your email with the provider before continuing.")

- The social provider did not return `email_verified=true`.
- Verify the email in the Auth0/social provider account.

### Provider button does not appear

- Only Google and GitHub have buttons. Check that the provider is included in `AUTH0_PROVIDERS`.
- Check that the provider is enabled in Auth0 **Authentication > Social**.

## Related Documentation

- [Auth0 Setup (user guide)](opbx-userguide/installation/auth0-setup.mdx)
- [Auth0 Syndicated Auth Design Spec](superpowers/specs/2026-06-25-auth0-syndicated-auth-design.md)
- [Auth0 Syndicated Auth Implementation Plan](superpowers/plans/2026-06-25-auth0-syndicated-auth-implementation-plan.md)
