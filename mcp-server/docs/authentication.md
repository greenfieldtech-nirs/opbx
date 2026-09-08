# Authentication

opbx-mcp never issues, stores, or exposes OPBX credentials. The MCP client presents
its **own OPBX credential**; the server validates/derives identity from it and forwards
it (in-memory, per request) to the OPBX REST API.

## Credential types

### 1. Sanctum Personal Access Token (`id|token…`)

- Resolved via `GET /api/v1/auth/me` → `{ user: { id, organization_id, role, status, is_platform_manager, organization } }`.
- Identity cached for `AUTH_IDENTITY_CACHE_TTL_SECONDS` (default 300s), keyed by
  SHA-256 of the token, in memory only.
- **Lifecycle caveat (upstream):** PATs expire after 24h and are revoked by any
  login/refresh/password change on that account. Prefer API keys for long-lived agents.

### 2. Scoped API key (`opbxk_…`)

- The intended machine credential: never expires, revocable, per-resource read/write
  grants, deny-by-default (`EnforceApiKeyScope`).
- OPBX has **no identity echo endpoint** for keys (keys cannot call `/auth/me`):
  the MCP identity is minimal (`principalType: "apikey"`), and resource authorization
  is delegated to OPBX. Tenant scoping is implicit and enforced upstream.
- Grantable resources (23): users, extensions, conference-rooms, ai-assistants,
  ai-assistant-providers, ring-groups, ai-assistant-load-balancers, ivr-menus,
  business-hours, phone-numbers, outbound-whitelist, inbound-blacklist,
  call-detail-records, recordings, call-tracking-campaigns, call-tracking-analytics,
  call-tracking-sessions, call-tracking-ad-platform-integrations, supervisors,
  auto-dialer-campaigns, distribution-lists, session-updates, call-notifications.
- Credential-bearing subroutes are never key-accessible, even under a granted parent
  (`extensions.password`, `extensions.reset-password`, `users.password.update`,
  `users.embed-token.*`).

### What is never accepted

- `organization_id` (or any tenant selector) in tool arguments — tenancy comes from
  the credential, always. Attempts are dropped by schema validation and never forwarded.
- `X-Operate-As-Organization` — the platform-manager impersonation header is never sent.

## Identity flow

```
Authorization: Bearer <credential>
        │
        ▼
IdentityResolver.resolve()
  ├─ starts with "opbxk_" → { principalType: "apikey" }        (no upstream call)
  └─ otherwise           → GET /api/v1/auth/me → cached identity
        │                     { userId, organizationId, role, isPlatformManager }
        ▼
McpRequestContext { identity, opbx: OpbxClient(credential), requestId, traceId }
```

Failures (missing/malformed header, unknown role, suspended org, invalid credential)
return HTTP 401 with a JSON-RPC error body before any MCP processing.

## Platform managers

A user with `is_platform_manager` can use the tenant tools against their own org.
The cross-tenant `/platform/*` API is intentionally **not exposed** here — it belongs
to a future, separately-privileged MCP server (see the inventory, `PLATFORM_ADMIN_ONLY`).
