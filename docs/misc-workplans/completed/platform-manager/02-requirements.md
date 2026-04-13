## 2. Requirements

### 2.1 Functional Requirements

#### PM-F01: Organization Listing & Overview

| Field | Detail |
|---|---|
| **ID** | PM-F01 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | None |

**Description:** Platform managers can view a paginated, searchable, sortable list of ALL organizations in the system regardless of tenant isolation.

**Acceptance Criteria:**
- [ ] List displays: name, slug, status, timezone, user count, extension count, DID count, created_at
- [ ] Searchable by organization name and slug (partial match)
- [ ] Filterable by status (active, suspended, deleted)
- [ ] Sortable by name (asc/desc), created_at (asc/desc), user count (asc/desc)
- [ ] Paginated with configurable page size (default 25, max 100)
- [ ] Response includes aggregated counts without N+1 queries

#### PM-F02: Organization Detail View

| Field | Detail |
|---|---|
| **ID** | PM-F02 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | PM-F01 |

**Description:** Platform managers can view full details of any organization including all related entities.

**Acceptance Criteria:**
- [ ] Displays full organization record (name, slug, status, timezone, settings, timestamps)
- [ ] Shows list of all users in the organization with roles and statuses
- [ ] Shows count of extensions, DIDs, ring groups, business hours configurations
- [ ] Cloudonix settings are displayed with API keys/tokens masked (show last 4 characters only)
- [ ] Navigation to sub-entity management from the detail view

#### PM-F03: Organization Status Management

| Field | Detail |
|---|---|
| **ID** | PM-F03 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | PM-F02 |

**Description:** Platform managers can change organization status: activate, suspend, or soft-delete.

**Acceptance Criteria:**
- [ ] Can transition organization status: active → suspended, suspended → active, active → deleted, suspended → deleted
- [ ] Suspending an organization immediately prevents all its users from authenticating (EnsureTenantScope middleware checks org status)
- [ ] Activating a suspended organization restores login capability for all its users
- [ ] Soft-deleting sets status to `deleted` and triggers soft delete timestamp
- [ ] Cannot delete an already-deleted organization
- [ ] Each status change is audit-logged with reason (optional text field)

#### PM-F04: Organization Settings Override

| Field | Detail |
|---|---|
| **ID** | PM-F04 |
| **Priority** | P1 (Should Have) |
| **Dependencies** | PM-F02 |

**Description:** Platform managers can modify organization settings and Cloudonix configuration for any organization.

**Acceptance Criteria:**
- [ ] Can update organization name, timezone, and settings JSON
- [ ] Can update Cloudonix settings (API token, domain, SIP settings) for any organization
- [ ] Settings updates are validated with the same rules as tenant-scoped updates
- [ ] All updates are audit-logged with before/after values

#### PM-F05: User Management Across Organizations

| Field | Detail |
|---|---|
| **ID** | PM-F05 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | PM-F01 |

**Description:** Platform managers can view, create, update, and delete users in any organization, and can set/unset the `is_platform_manager` flag.

**Acceptance Criteria:**
- [ ] Can list all users across all organizations (with org filter)
- [ ] Can list users within a specific organization
- [ ] Can create a new user in any organization with any valid role
- [ ] Can update user fields (name, email, role, status, phone, address fields) in any organization
- [ ] Can delete users in any organization
- [ ] Can set `is_platform_manager = true` on any user
- [ ] Can set `is_platform_manager = false` on any user (including self, with confirmation)
- [ ] The `is_platform_manager` flag is NEVER exposed or modifiable through normal tenant-scoped user CRUD endpoints
- [ ] Cannot remove the last platform manager (system must always have at least one)

#### PM-F06: Platform Manager Bootstrap

| Field | Detail |
|---|---|
| **ID** | PM-F06 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | Database migration |

**Description:** Artisan commands for creating and managing platform manager designations.

**Acceptance Criteria:**
- [ ] `php artisan opbx:create-platform-manager` — interactive command that prompts for email, name, password, organization name; creates user+org if not exists, sets flag if user exists
- [ ] `php artisan opbx:set-platform-manager {email}` — sets `is_platform_manager=true` on existing user identified by email
- [ ] `php artisan opbx:revoke-platform-manager {email}` — sets `is_platform_manager=false` on existing user; refuses if this is the last platform manager
- [ ] All commands produce clear console output confirming the action taken
- [ ] All commands are idempotent (running set on an already-set user succeeds with a notice)

#### PM-F07: Platform Dashboard

| Field | Detail |
|---|---|
| **ID** | PM-F07 |
| **Priority** | P1 (Should Have) |
| **Dependencies** | PM-F01, PM-F05 |

**Description:** A dashboard view showing platform-wide statistics and recent activity.

**Acceptance Criteria:**
- [ ] Displays total organizations count (by status)
- [ ] Displays total users count (by status)
- [ ] Displays total extensions, DIDs counts
- [ ] Lists 10 most recently created organizations
- [ ] Lists 10 most recent platform manager audit log entries
- [ ] All stats are computed via efficient aggregate queries

#### PM-F08: Platform Activity Log

| Field | Detail |
|---|---|
| **ID** | PM-F08 |
| **Priority** | P0 (Must Have) |
| **Dependencies** | Audit logging infrastructure |

**Description:** A searchable log of all platform manager actions.

**Acceptance Criteria:**
- [ ] Lists all entries from `platform_audit_logs` table
- [ ] Each entry shows: timestamp, platform manager name/email, action type, target organization, target entity type/id, details
- [ ] Filterable by: platform manager user, target organization, action type, date range
- [ ] Sortable by timestamp (default: newest first)
- [ ] Paginated with configurable page size
- [ ] Details column shows JSON diff of before/after for update actions

### 2.2 Non-Functional Requirements

#### PM-NF01: Security

| Field | Detail |
|---|---|
| **ID** | PM-NF01 |
| **Priority** | P0 (Must Have) |

- [ ] `is_platform_manager` column is excluded from mass-assignable attributes on the User model (`$fillable` must NOT include it)
- [ ] Normal user CRUD API endpoints (`/api/v1/users`) never expose or accept the `is_platform_manager` field
- [ ] Platform management API endpoints are in a completely separate route group with `platform.manager` middleware
- [ ] `platform.manager` middleware returns 403 if `is_platform_manager !== true`
- [ ] All platform manager cross-tenant actions are recorded in `platform_audit_logs` before execution
- [ ] Platform manager Sanctum tokens include `platform:*` ability in addition to all role-based abilities
- [ ] The `OrganizationScope` bypass mechanism uses an explicit method call, never a global toggle

#### PM-NF02: Performance

| Field | Detail |
|---|---|
| **ID** | PM-NF02 |
| **Priority** | P1 (Should Have) |

- [ ] Organization listing with counts uses `withCount()` or subqueries — no N+1
- [ ] `is_platform_manager` column is indexed for fast lookups
- [ ] Audit log table has indexes on `platform_manager_user_id`, `target_organization_id`, `action`, `created_at`
- [ ] Pagination is cursor-based or offset-based with indexed columns
- [ ] Cross-org queries explicitly remove the global scope only for the specific query, not globally

#### PM-NF03: Backwards Compatibility

| Field | Detail |
|---|---|
| **ID** | PM-NF03 |
| **Priority** | P0 (Must Have) |

- [ ] All existing tests pass without modification after the migration
- [ ] The `UserRole` enum is NOT modified — no new cases added
- [ ] Existing `canManageOrganization()`, `canManageUsers()`, etc. methods remain unchanged
- [ ] The `OrganizationScope` global scope continues to work identically for non-platform-manager requests
- [ ] The `EnsureTenantScope` middleware continues to work identically for non-platform-manager requests
- [ ] Frontend `UserRole` TypeScript type is NOT modified
- [ ] Existing sidebar navigation is unaffected for non-platform-manager users
- [ ] Login flow works identically; `is_platform_manager` is simply included in the user response payload

---

