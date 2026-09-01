# Security

## Layers

1. **Transport auth** — Bearer credential required on every `/mcp` request (401 otherwise).
2. **Identity** — resolved/validated against OPBX per request (see authentication.md).
3. **RBAC per invocation** — every tool declares `requiredRoles` mirroring OPBX policy
   (verified against `app/Policies/*`). The check runs on **every call**, independent of
   `tools/list` visibility. Role order is not assumed; explicit role lists only.
4. **Where OPBX is permissive, MCP is stricter.** Three upstream endpoints have no role
   authorization at all (`ivr-menus` mutations, `business-hours`/`ivr-menus`
   `toggle-status`) — MCP gates them behind owner|pbx_admin.
5. **Rate limiting** — edge limit + per-tool classes (see architecture.md).
6. **Confirmation gate** — high-impact operations require `confirm: true` (below).
7. **Secret hygiene** — SIP passwords, extension password endpoints, webphone/embed
   credentials, Cloudonix settings, and API-key management are classified
   `INTERNAL_NOT_EXPOSED` and have no MCP surface. Logs redact credential-shaped fields;
   upstream 5xx bodies are never forwarded (framework internals leak risk).

## Confirmation model

Tools with `confirmation: "required"` (all deletes, campaign lifecycle, disconnect_call,
start_call_coaching, assign_distribution_list, security-rule removals) use a two-step
flow:

1. First call **without** `confirm` → returns `confirmation_required: true` plus a live
   **preview** (target entity fetched read-only, plus warnings about upstream side
   effects — e.g. extension delete removes the Cloudonix subscriber; assigning a list
   to a draft campaign activates it).
2. Re-invoke with `confirm: true` → executes.

`confirm` must be the boolean `true` — strings are schema-rejected. SDK elicitation was
evaluated and rejected: it depends on client capability support; the explicit argument
works with every MCP client and is auditable in logs.

## RBAC mapping (OPBX → MCP)

| OPBX role | MCP surface |
|---|---|
| owner | everything |
| pbx_admin | configuration tools; owner-only exceptions stay owner-only (delete_campaign, archive_campaign, disconnect_call, delete_distribution_list for non-failed lists, owner role assignment) |
| pbx_user | reads + update of own extension (upstream-enforced) |
| reporter | read tools only (config reads, CDRs, analytics) |
| supervisor | reads + scoped views + `start_call_coaching` (upstream scope-enforced) |
| `opbxk_` key | its configured resource grants, enforced upstream; Sanctum-only groups unavailable |

## Tenant isolation

- The organization is derived from the credential (upstream `OrganizationScope`).
- Cross-tenant access is masked as 404 upstream and preserved as such.
- Tests: `tests/security/security.test.ts` covers org-override, injection, RBAC,
  confirmation bypass, identity-cache isolation.

## Audit

Every tool call is structured-logged with request_id, trace_id, tool name, permission,
organization_id and user_id. `elevated` audit level applies to destructive tools.
OPBX additionally audit-logs sensitive mutations (e.g. user deletion) upstream.
