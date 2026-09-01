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
  grants, deny-by-default (`EnforceApiKeyScope`), 12 grantable resources.
- OPBX has **no identity echo endpoint** for keys (keys cannot call `/auth/me`):
  the MCP identity is minimal (`principalType: "apikey"`), and resource authorization
  is delegated to OPBX. Tenant scoping is implicit and enforced upstream.
- Keys cannot reach the Sanctum-only route groups (campaigns, session-updates/live
  calls, call notifications) — calls there return a clear `authentication_error`
  explaining the limitation. Use a PAT for those.

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
