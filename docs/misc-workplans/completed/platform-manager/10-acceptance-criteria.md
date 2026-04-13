## 10. Acceptance Criteria

All criteria below must pass before the Platform Manager feature is considered complete.

### 10.1 Organization Management

- [ ] **AC-10.1.1** — A platform manager can retrieve a paginated list of **all** organizations via `GET /api/v1/platform/organizations` regardless of their own `organization_id`
- [ ] **AC-10.1.2** — The organization list supports filtering by `status` and returns only matching records
- [ ] **AC-10.1.3** — The organization list supports search by `name` (partial match, case-insensitive)
- [ ] **AC-10.1.4** — Each organization in the list response includes `users_count`, `extensions_count`, `dids_count` (no N+1)
- [ ] **AC-10.1.5** — A platform manager can retrieve full details of any single organization including its users
- [ ] **AC-10.1.6** — A platform manager can transition an organization from `active` → `suspended`
- [ ] **AC-10.1.7** — A platform manager can transition an organization from `suspended` → `active`
- [ ] **AC-10.1.8** — A platform manager can transition an organization to `deleted` (soft delete)
- [ ] **AC-10.1.9** — Invalid status transitions return `422` with a descriptive error message
- [ ] **AC-10.1.10** — A platform manager can update organization name, timezone, and settings
- [ ] **AC-10.1.11** — All organization mutations generate an audit log entry with correct `before_state` and `after_state`

### 10.2 User Management

- [ ] **AC-10.2.1** — A platform manager can list users across **all** organizations
- [ ] **AC-10.2.2** — The user list supports filtering by `organization_id`, `role`, `status`, `is_platform_manager`
- [ ] **AC-10.2.3** — A platform manager can view any user's details including their organization info
- [ ] **AC-10.2.4** — A platform manager can create a user in **any** organization
- [ ] **AC-10.2.5** — A platform manager can update any user's fields (name, email, role, status)
- [ ] **AC-10.2.6** — A platform manager can delete any user
- [ ] **AC-10.2.7** — Attempting to delete self returns `403`
- [ ] **AC-10.2.8** — Attempting to delete the last `owner` of an organization returns `422`
- [ ] **AC-10.2.9** — All user mutations generate an audit log entry

### 10.3 Platform Manager Flag Management

- [ ] **AC-10.3.1** — A platform manager can set `is_platform_manager=true` on any user via `PATCH /api/v1/platform/users/{id}/platform-manager`
- [ ] **AC-10.3.2** — A platform manager can revoke `is_platform_manager` from any user
- [ ] **AC-10.3.3** — Revoking the flag from the **last remaining platform manager** returns `422`
- [ ] **AC-10.3.4** — Granting the flag to a user who already has it is idempotent (returns `200`)
- [ ] **AC-10.3.5** — Flag changes are captured in the audit log with before/after state
- [ ] **AC-10.3.6** — The `is_platform_manager` flag is NEVER modifiable through normal tenant-scoped user CRUD endpoints

### 10.4 Audit Logging

- [ ] **AC-10.4.1** — Every mutating platform endpoint creates a `platform_audit_logs` record
- [ ] **AC-10.4.2** — Each audit record contains all required fields (manager ID, action, target, before/after, IP, UA, timestamp)
- [ ] **AC-10.4.3** — Audit logs are retrievable via `GET /api/v1/platform/audit-logs` with pagination
- [ ] **AC-10.4.4** — Audit logs can be filtered by platform manager, target organization, action type, and date range
- [ ] **AC-10.4.5** — Audit log records cannot be updated or deleted through any API endpoint
- [ ] **AC-10.4.6** — The audit log `action` field uses consistent naming: `organization.updated`, `organization.status.updated`, `user.created`, `user.updated`, `user.deleted`, `user.platform_manager.granted`, `user.platform_manager.revoked`

### 10.5 Artisan Commands

- [ ] **AC-10.5.1** — `opbx:create-platform-manager` interactively creates a user with `is_platform_manager=true`
- [ ] **AC-10.5.2** — `opbx:set-platform-manager {email}` sets the flag on an existing user
- [ ] **AC-10.5.3** — `opbx:revoke-platform-manager {email}` clears the flag
- [ ] **AC-10.5.4** — `opbx:revoke-platform-manager` refuses to revoke the last platform manager (exit code 1)
- [ ] **AC-10.5.5** — All commands are idempotent
- [ ] **AC-10.5.6** — Nonexistent email input returns clear error with exit code 1

### 10.6 Frontend

- [ ] **AC-10.6.1** — Platform sidebar section appears only when `user.is_platform_manager === true`
- [ ] **AC-10.6.2** — `/ui/platform/dashboard` renders platform-wide statistics
- [ ] **AC-10.6.3** — `/ui/platform/organizations` renders a searchable, filterable organization table
- [ ] **AC-10.6.4** — `/ui/platform/organizations/:id` renders organization detail with status management
- [ ] **AC-10.6.5** — `/ui/platform/users` renders a cross-organization user table
- [ ] **AC-10.6.6** — `/ui/platform/audit-log` renders a filterable audit log viewer
- [ ] **AC-10.6.7** — `PlatformManagerRoute` guard redirects non-PM users to `/ui/dashboard`
- [ ] **AC-10.6.8** — All platform pages display proper loading, error, and empty states

### 10.7 Security

- [ ] **AC-10.7.1** — `is_platform_manager` cannot be set via mass assignment
- [ ] **AC-10.7.2** — Normal API endpoints remain completely unaffected
- [ ] **AC-10.7.3** — `EnsurePlatformManager` middleware returns `403` for any non-PM user
- [ ] **AC-10.7.4** — The `OrganizationScope` bypass counter returns to `0` after every request
- [ ] **AC-10.7.5** — An unauthenticated request to platform endpoints receives `401`

### 10.8 Backward Compatibility

- [ ] **AC-10.8.1** — All existing tests pass without modification
- [ ] **AC-10.8.2** — The `UserRole` enum is NOT modified
- [ ] **AC-10.8.3** — Existing authentication flows work identically
- [ ] **AC-10.8.4** — The `is_platform_manager` column defaults to `false` — no existing user data affected
- [ ] **AC-10.8.5** — Frontend `UserRole` TypeScript type is NOT modified

### 10.9 Performance

- [ ] **AC-10.9.1** — Organization list uses `withCount()` — no N+1 queries
- [ ] **AC-10.9.2** — User list eager-loads `organization` — no N+1 queries
- [ ] **AC-10.9.3** — The `is_platform_manager` column is indexed
- [ ] **AC-10.9.4** — Audit log queries use indexed columns for filtering and sorting
- [ ] **AC-10.9.5** — Platform list endpoints return a maximum of 100 records per page (enforced server-side)

---

