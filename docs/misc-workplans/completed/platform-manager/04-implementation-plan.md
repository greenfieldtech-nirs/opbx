## 4. Implementation Plan

### Phase 1: Database & Backend Foundation

> **Status:** NOT STARTED
> **Estimated Effort:** 2-3 days
> **Dependencies:** None

- [ ] **[PM-1.1.1]** Create migration: add `is_platform_manager` boolean column to `users` table
  - **File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_is_platform_manager_to_users_table.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Boolean column, default false, indexed. See schema in Section 3.1.1.

- [ ] **[PM-1.1.2]** Create migration: create `platform_audit_logs` table
  - **File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_platform_audit_logs_table.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.1]
  - **Details:** Full schema in Section 3.1.2. Must include all indexes.

- [ ] **[PM-1.1.3]** Create OrganizationStatus enum
  - **File:** `app/Enums/OrganizationStatus.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Enum with ACTIVE, SUSPENDED, DELETED cases. Includes label(), color(), allowsAuthentication(), and values() methods. See Section 3.1.4.

- [ ] **[PM-1.1.4]** Create migration: add `status` column to `organizations` table (if not exists)
  - **File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_status_to_organizations_table.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.3]
  - **Details:** Adds status column with default 'active', indexed. Skips if column already exists. See Section 3.1.3.

- [ ] **[PM-1.2.1]** Update User model: add `is_platform_manager` to `$casts`, add `isPlatformManager()` method, add `platformAuditLogs()` relationship, add `revokeAllTokens()` method
  - **File:** `app/Models/User.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.1]
  - **Details:** Do NOT add to `$fillable`. Add to `$casts` as `'boolean'`. Add `isPlatformManager(): bool` method. Add `platformAuditLogs(): HasMany` relationship. See Section 3.2.6.

- [ ] **[PM-1.2.2]** Create PlatformAuditLog model
  - **File:** `app/Models/PlatformAuditLog.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.2]
  - **Details:** Full model code in Section 3.2.5. Includes `$fillable`, `$casts`, `platformManager()` and `targetOrganization()` relationships.

- [ ] **[PM-1.3.1]** Create PlatformAuditService
  - **File:** `app/Services/PlatformAuditService.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.2]
  - **Details:** Single `log()` method. Full code in Section 3.2.4.

- [ ] **[PM-1.4.1]** Modify OrganizationScope: add bypass mechanism
  - **File:** `app/Scopes/OrganizationScope.php`
  - **Complexity:** M
  - **Dependencies:** None
  - **Details:** Add static `$bypassCount`, `bypass(callable)`, `isBypassed()`. Modify `apply()` to check bypass. See Section 3.2.2. CRITICAL: Existing `apply()` logic must remain 100% unchanged when not bypassed. Use a counter (not a simple boolean) to support nested bypass calls safely.

- [ ] **[PM-1.5.1]** Create EnsurePlatformManager middleware
  - **File:** `app/Http/Middleware/EnsurePlatformManager.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Full code in Section 3.2.3. Returns 403 JSON response if user is null or `is_platform_manager` is not true.

- [ ] **[PM-1.5.2]** Register EnsurePlatformManager middleware alias
  - **File:** `bootstrap/app.php` (or `app/Http/Kernel.php` depending on Laravel 12 setup)
  - **Complexity:** S
  - **Dependencies:** [PM-1.5.1]
  - **Details:** Register middleware with alias `platform.manager`. In Laravel 12 this is typically done in `bootstrap/app.php` via `->withMiddleware()`.

- [ ] **[PM-1.6.1]** Update AuthController: include `platform:*` abilities in token for platform managers
  - **File:** `app/Http/Controllers/AuthController.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** After determining base token abilities from role, check `isPlatformManager()` and merge platform abilities. See Section 3.2.7.

- [ ] **[PM-1.7.1]** Create platform route file and register it
  - **File:** `routes/platform.php`, `routes/api.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.5.2]
  - **Details:** Create `routes/platform.php` with route group structure from Section 3.3.1. Add `require __DIR__.'/platform.php';` at end of `routes/api.php`. Routes initially point to controllers that will be created in Phase 2.

- [ ] **[PM-1.8.1]** Add route model binding override for platform routes
  - **File:** `app/Providers/AppServiceProvider.php` (or `RouteServiceProvider.php`)
  - **Complexity:** S
  - **Dependencies:** [PM-1.4.1], [PM-1.7.1]
  - **Details:** Override binding for `organization` parameter to bypass OrganizationScope when the request matches `api/v1/platform/*`. See Section 3.3.2.

- [ ] **[PM-1.8.2]** Update EnsureTenantScope middleware to check organization status
  - **File:** `app/Http/Middleware/EnsureTenantScope.php` (or equivalent existing middleware)
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.3], [PM-1.1.4]
  - **Details:** After validating tenant scope, check if the user's organization status is 'suspended'. If suspended, return 403 with message "Your organization has been suspended." Do NOT check for platform managers (they bypass tenant scope entirely).

- [ ] **[PM-1.9.1]** Run migrations and verify existing tests pass
  - **Complexity:** S
  - **Dependencies:** [PM-1.1.1], [PM-1.1.2]
  - **Details:** Run `php artisan migrate`, then run `./run-tests.sh`. All existing tests must pass. The new column defaults to `false` so no existing behavior changes.

### Phase 2: API Endpoints

> **Status:** NOT STARTED
> **Estimated Effort:** 3-4 days
> **Dependencies:** Phase 1 complete

- [ ] **[PM-2.1.1]** Create PlatformDashboardController
  - **File:** `app/Http/Controllers/Platform/PlatformDashboardController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.4.1], [PM-1.7.1]
  - **Details:** Single `index()` method that uses `OrganizationScope::bypass()` to aggregate counts across all organizations. Returns `PlatformDashboardStats` structure. Includes: org counts by status, user counts by status, extension/DID totals, 10 most recent orgs, 10 most recent audit log entries.

- [ ] **[PM-2.2.1]** Create PlatformOrganizationController — index method
  - **File:** `app/Http/Controllers/Platform/PlatformOrganizationController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.4.1], [PM-1.7.1]
  - **Details:** `index()` method: paginated list of all organizations with `withCount(['users', 'extensions', 'dids'])`. Supports search (name, slug), status filter, sort by name/created_at/users_count. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.2.2]** Create PlatformOrganizationController — show method
  - **File:** `app/Http/Controllers/Platform/PlatformOrganizationController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.2.1]
  - **Details:** `show()` method: loads single organization with users, and counts for extensions, DIDs, ring groups, business hours. Masks sensitive Cloudonix settings fields (API keys show last 4 chars only). Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.2.3]** Create PlatformOrganizationController — update method
  - **File:** `app/Http/Controllers/Platform/PlatformOrganizationController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.2.1], [PM-1.3.1]
  - **Details:** `update()` method: updates organization name, timezone, settings. Validates input. Audit-logs the change with before/after state. Uses `OrganizationScope::bypass()` for the update.

- [ ] **[PM-2.2.4]** Create PlatformOrganizationController — updateStatus method
  - **File:** `app/Http/Controllers/Platform/PlatformOrganizationController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.2.1], [PM-1.3.1]
  - **Details:** `updateStatus()` method: accepts `status` and optional `reason`. Validates status transitions (active→suspended, suspended→active, active→deleted, suspended→deleted). For soft-delete, calls `$organization->delete()`. Audit-logs with before/after status and reason.

- [ ] **[PM-2.2.5]** Create UpdateOrganizationStatusRequest form request
  - **File:** `app/Http/Requests/Platform/UpdateOrganizationStatusRequest.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Validates `status` is one of `active`, `suspended`, `deleted`. Validates `reason` is optional string, max 500 chars.

- [ ] **[PM-2.2.6]** Create UpdateOrganizationSettingsRequest form request
  - **File:** `app/Http/Requests/Platform/UpdateOrganizationSettingsRequest.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Validates `name` (optional, string, max 255), `timezone` (optional, string, valid timezone), `settings` (optional, array).

- [ ] **[PM-2.3.1]** Create PlatformUserController — index method (cross-org)
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.4.1], [PM-1.7.1]
  - **Details:** `index()` method: paginated list of ALL users across all organizations. Eager-loads organization name. Supports search (name, email), filter by organization_id, role, status, is_platform_manager. Sortable by name, email, created_at. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.2]** Create PlatformUserController — indexByOrganization method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** S
  - **Dependencies:** [PM-2.3.1]
  - **Details:** `indexByOrganization()` method: paginated list of users within a specific organization. Same filtering/sorting as `index()` but scoped to the org. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.3]** Create PlatformUserController — show method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** S
  - **Dependencies:** [PM-2.3.1]
  - **Details:** `show()` method: returns single user with organization details. Uses `OrganizationScope::bypass()` to find user by ID.

- [ ] **[PM-2.3.4]** Create PlatformUserController — store method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.3.1], [PM-1.3.1]
  - **Details:** `store()` method: creates a new user in the specified organization. Validates all required fields. Hashes password. Sets `organization_id` from route parameter. Audit-logs user creation. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.5]** Create PlatformUserController — update method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.3.1], [PM-1.3.1]
  - **Details:** `update()` method: updates user fields (name, email, role, status, phone, address fields). Does NOT allow updating `is_platform_manager` (that is handled by a separate endpoint). Audit-logs with before/after. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.6]** Create PlatformUserController — destroy method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** S
  - **Dependencies:** [PM-2.3.1], [PM-1.3.1]
  - **Details:** `destroy()` method: deletes user. Cannot delete self. Cannot delete last owner of an organization. Audit-logs deletion with user state as `before_state`. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.7]** Create PlatformUserController — setPlatformManager method
  - **File:** `app/Http/Controllers/Platform/PlatformUserController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.3.1], [PM-1.3.1]
  - **Details:** `setPlatformManager()` method: accepts `{ is_platform_manager: boolean }`. If revoking, check this is not the last platform manager (count platform managers; if count <= 1 and revoking, return 422). Directly sets `is_platform_manager` on the user (bypasses mass assignment via `$user->is_platform_manager = $value; $user->save()`). Audit-logs the change. Uses `OrganizationScope::bypass()`.

- [ ] **[PM-2.3.8]** Create PlatformCreateUserRequest form request
  - **File:** `app/Http/Requests/Platform/PlatformCreateUserRequest.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Validates: `name` (required, string, max 255), `email` (required, email, unique in organization), `password` (required, min 8), `role` (required, in: owner, pbx_admin, pbx_user, reporter), `status` (optional, in: active, inactive, default active), `phone` (optional), address fields (optional).

- [ ] **[PM-2.3.9]** Create PlatformUpdateUserRequest form request
  - **File:** `app/Http/Requests/Platform/PlatformUpdateUserRequest.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Same fields as create but all optional. Email uniqueness check excludes current user ID.

- [ ] **[PM-2.3.10]** Create PlatformSetManagerRequest form request
  - **File:** `app/Http/Requests/Platform/PlatformSetManagerRequest.php`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Validates: `is_platform_manager` (required, boolean).

- [ ] **[PM-2.4.1]** Create PlatformAuditLogController — index method
  - **File:** `app/Http/Controllers/Platform/PlatformAuditLogController.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.2.2]
  - **Details:** `index()` method: paginated list of audit log entries. Eager-loads `platformManager` (name, email) and `targetOrganization` (name, slug). Filterable by `platform_manager_user_id`, `target_organization_id`, `action`, date range (`date_from`, `date_to`). Default sort: `created_at desc`. Does NOT need `OrganizationScope::bypass()` because `PlatformAuditLog` has no organization scope.

### Phase 3: Artisan Commands

> **Status:** NOT STARTED
> **Estimated Effort:** 1-2 days
> **Dependencies:** Phase 1 complete

- [ ] **[PM-3.1.1]** Create `opbx:create-platform-manager` command
  - **File:** `app/Console/Commands/CreatePlatformManager.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Interactive command. Signature: `opbx:create-platform-manager`. Steps:
    1. Prompt for email address
    2. Check if user exists (bypass OrganizationScope for lookup)
    3. If user exists: confirm setting `is_platform_manager = true`, do it, output success
    4. If user does not exist: prompt for name, password (with confirmation), organization name
    5. Check if organization name exists; if not, create new organization
    6. Create user with role `owner` in that organization, set `is_platform_manager = true`
    7. Output summary of what was created
    8. Handle all errors gracefully with console error messages

- [ ] **[PM-3.1.2]** Create `opbx:set-platform-manager` command
  - **File:** `app/Console/Commands/SetPlatformManager.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Signature: `opbx:set-platform-manager {email}`. Steps:
    1. Find user by email (bypass OrganizationScope)
    2. If not found, output error and exit with code 1
    3. If already a platform manager, output notice "User is already a platform manager" and exit with code 0
    4. Set `is_platform_manager = true`, save
    5. Output success message with user name and email

- [ ] **[PM-3.1.3]** Create `opbx:revoke-platform-manager` command
  - **File:** `app/Console/Commands/RevokePlatformManager.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Signature: `opbx:revoke-platform-manager {email}`. Steps:
    1. Find user by email (bypass OrganizationScope)
    2. If not found, output error and exit with code 1
    3. If not a platform manager, output notice "User is not a platform manager" and exit with code 0
    4. Count total platform managers (bypass scope); if count <= 1, output error "Cannot revoke the last platform manager" and exit with code 1
    5. Set `is_platform_manager = false`, save
    6. **Revoke all Sanctum tokens for the user** (force re-authentication)
    7. Output success message with token revocation notice

- [ ] **[PM-3.1.4]** Create `opbx:cleanup-audit-logs` command
  - **File:** `app/Console/Commands/CleanupPlatformAuditLogs.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.2]
  - **Details:** Signature: `opbx:cleanup-audit-logs {--days=14} {--dry-run}`. Deletes audit logs older than 14 days (configurable). Supports dry-run mode. Should be scheduled to run daily. See Section 3.2.7.

- [ ] **[PM-3.1.5]** Schedule audit log cleanup command
  - **File:** `app/Console/Kernel.php` (or equivalent in Laravel 12)
  - **Complexity:** S
  - **Dependencies:** [PM-3.1.4]
  - **Details:** Add `$schedule->command('opbx:cleanup-audit-logs')->daily();` to the scheduler.

### Phase 4: Frontend Foundation

> **Status:** NOT STARTED
> **Estimated Effort:** 2 days
> **Dependencies:** Phase 1 [PM-1.2.1] (User model change), Phase 2 (API endpoints exist)

- [ ] **[PM-4.1.1]** Update User TypeScript type to include `is_platform_manager`
  - **File:** `frontend/src/types/auth.ts` (or wherever the `User` interface is defined)
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Add `is_platform_manager: boolean` to the existing `User` interface/type. This field comes from the API response for `/api/v1/auth/me` and login responses.

- [ ] **[PM-4.1.2]** Create platform TypeScript types file
  - **File:** `frontend/src/types/platform.ts`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Full types as specified in Section 3.4.3. Includes: `PlatformOrganization`, `PlatformOrganizationDetail`, `PlatformUser`, `PlatformAuditLogEntry`, `PlatformDashboardStats`, and all parameter interfaces.

- [ ] **[PM-4.2.1]** Create platform API service
  - **File:** `frontend/src/services/platformApi.ts`
  - **Complexity:** M
  - **Dependencies:** [PM-4.1.2]
  - **Details:** Full API client as specified in Section 3.4.4. All methods for dashboard, organizations, users, audit logs.

- [ ] **[PM-4.3.1]** Create PlatformManagerRoute guard component
  - **File:** `frontend/src/components/platform/PlatformManagerRoute.tsx`
  - **Complexity:** S
  - **Dependencies:** [PM-4.1.1]
  - **Details:** Route guard that checks `user.is_platform_manager`. Renders `<Outlet />` if authorized. Shows PlatformErrorPage with 403 message if user is not a platform manager.

- [ ] **[PM-4.3.1a]** Create PlatformErrorPage component
  - **File:** `frontend/src/components/platform/PlatformErrorPage.tsx`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Error page for platform routes. Shows 403 Forbidden with "Access Denied" message for non-platform-managers. Includes a button to return to dashboard. Does NOT redirect - shows the error inline.

- [ ] **[PM-4.3.2]** Create PlatformLayout component
  - **File:** `frontend/src/components/platform/PlatformLayout.tsx`
  - **Complexity:** S
  - **Dependencies:** [PM-4.3.1]
  - **Details:** Wrapper layout for platform pages. Includes a breadcrumb showing "Platform > [current page]". Uses the same overall layout structure as the main app but with a visual indicator (e.g., a subtle top banner or different accent color) to distinguish platform management context from normal tenant context.

- [ ] **[PM-4.3.3]** Create OrganizationStatusBadge component
  - **File:** `frontend/src/components/platform/OrganizationStatusBadge.tsx`
  - **Complexity:** S
  - **Dependencies:** None
  - **Details:** Renders a colored badge for organization status. `active` = green, `suspended` = yellow/amber, `deleted` = red/gray. Uses existing shadcn/ui Badge component with appropriate variant.

- [ ] **[PM-4.3.4]** Create AuditLogEntry component
  - **File:** `frontend/src/components/platform/AuditLogEntry.tsx`
  - **Complexity:** S
  - **Dependencies:** [PM-4.1.2]
  - **Details:** Renders a single audit log entry in a consistent format. Shows timestamp, actor (platform manager name), action description, target org/entity, expandable JSON diff for before/after states.

- [ ] **[PM-4.4.1]** Create platform TanStack Query hooks
  - **Files:**
    - `frontend/src/hooks/platform/usePlatformDashboard.ts`
    - `frontend/src/hooks/platform/usePlatformOrganizations.ts`
    - `frontend/src/hooks/platform/usePlatformOrganization.ts`
    - `frontend/src/hooks/platform/usePlatformUsers.ts`
    - `frontend/src/hooks/platform/usePlatformUser.ts`
    - `frontend/src/hooks/platform/usePlatformAuditLogs.ts`
  - **Complexity:** M
  - **Dependencies:** [PM-4.2.1]
  - **Details:** Each hook wraps the corresponding `platformApi` method with TanStack Query's `useQuery` or `useMutation`. Query keys follow the pattern `['platform', 'resource', params]`. Mutations invalidate relevant queries on success. Example:

    ```typescript
    // usePlatformOrganizations.ts
    export function usePlatformOrganizations(params?: PlatformOrganizationsParams) {
      return useQuery({
        queryKey: ['platform', 'organizations', params],
        queryFn: () => platformApi.getOrganizations(params).then(r => r.data),
      });
    }

    export function useUpdateOrganizationStatus() {
      const queryClient = useQueryClient();
      return useMutation({
        mutationFn: ({ id, data }: { id: number; data: { status: string; reason?: string } }) =>
          platformApi.updateOrganizationStatus(id, data),
        onSuccess: () => {
          queryClient.invalidateQueries({ queryKey: ['platform', 'organizations'] });
        },
      });
    }
    ```

- [ ] **[PM-4.5.1]** Add platform routes to router configuration
  - **File:** `frontend/src/router.tsx`
  - **Complexity:** S
  - **Dependencies:** [PM-4.3.1]
  - **Details:** Add lazy-loaded routes under a `platform` path with `PlatformManagerRoute` as the element. Full configuration in Section 3.4.6.

- [ ] **[PM-4.5.2]** Add platform section to sidebar navigation
  - **File:** `frontend/src/components/Sidebar.tsx` (or wherever sidebar nav is configured)
  - **Complexity:** S
  - **Dependencies:** [PM-4.1.1]
  - **Details:** Conditionally add "Platform" navigation section when `user.is_platform_manager === true`. Section contains: Dashboard, Organizations, All Users, Activity Log. See Section 3.4.5.

### Phase 5: Frontend Pages

> **Status:** NOT STARTED
> **Estimated Effort:** 4-5 days
> **Dependencies:** Phase 4 complete

- [ ] **[PM-5.1.1]** Create PlatformDashboard page
  - **File:** `frontend/src/pages/platform/PlatformDashboard.tsx`
  - **Complexity:** M
  - **Dependencies:** [PM-4.4.1]
  - **Details:** Page displaying platform-wide statistics. Layout:
    - Top row: 4 stat cards (Total Organizations, Total Users, Total Extensions, Total DIDs) with status breakdowns
    - Middle row: Recent organizations table (10 rows, columns: name, status, user count, created date)
    - Bottom row: Recent audit log entries (10 rows, using AuditLogEntry component)
    - Uses `usePlatformDashboard` hook
    - Loading and error states handled

- [ ] **[PM-5.2.1]** Create PlatformOrganizations page
  - **File:** `frontend/src/pages/platform/PlatformOrganizations.tsx`
  - **Complexity:** L
  - **Dependencies:** [PM-4.4.1], [PM-4.3.3]
  - **Details:** Full organization listing page. Components:
    - Search input (debounced, 300ms) for name/slug search
    - Status filter dropdown (All, Active, Suspended, Deleted)
    - Sort controls (columns: Name, Created, Users — clickable headers)
    - Data table with columns: Name (link to detail), Slug, Status (badge), Timezone, Users, Extensions, DIDs, Created
    - Pagination controls (page size selector: 10/25/50/100, prev/next buttons, page indicator)
    - Uses `usePlatformOrganizations` hook with query params synced to URL search params

- [ ] **[PM-5.2.2]** Create PlatformOrganizationDetail page
  - **File:** `frontend/src/pages/platform/PlatformOrganizationDetail.tsx`
  - **Complexity:** L
  - **Dependencies:** [PM-4.4.1], [PM-4.3.3]
  - **Details:** Organization detail page with multiple sections:
    - **Header:** Organization name, status badge, action buttons (Change Status, Edit Settings)
    - **Info section:** slug, timezone, created_at, updated_at, settings JSON viewer
    - **Status management dialog:** Dropdown to select new status, optional reason text field, confirm button. Shows warning for destructive actions (suspend, delete). Uses `useUpdateOrganizationStatus` mutation.
    - **Settings edit dialog:** Form to edit name, timezone, settings. Uses `useUpdateOrganization` mutation.
    - **Users tab:** Table of users in this organization (name, email, role, status, is_platform_manager badge). Links to user detail. "Add User" button.
    - **Counts section:** Cards showing Extensions, DIDs, Ring Groups, Business Hours counts
    - Uses `usePlatformOrganization` hook with org ID from URL params

- [ ] **[PM-5.3.1]** Create PlatformUsers page
  - **File:** `frontend/src/pages/platform/PlatformUsers.tsx`
  - **Complexity:** L
  - **Dependencies:** [PM-4.4.1]
  - **Details:** Cross-organization user listing page. Components:
    - Search input (debounced) for name/email search
    - Filter controls: Organization dropdown (searchable), Role dropdown, Status dropdown, Platform Manager toggle
    - Data table: Name (link), Email, Organization (link to org detail), Role, Status, Platform Manager (badge), Created
    - Pagination controls
    - Uses `usePlatformUsers` hook with query params synced to URL

- [ ] **[PM-5.3.2]** Create PlatformUserDetail page
  - **File:** `frontend/src/pages/platform/PlatformUserDetail.tsx`
  - **Complexity:** M
  - **Dependencies:** [PM-4.4.1]
  - **Details:** User detail and edit page:
    - **Header:** User name, organization name (link), role badge, status badge, platform manager badge
    - **Edit form:** Name, email, phone, role dropdown, status dropdown, address fields. Save button triggers `useUpdatePlatformUser` mutation.
    - **Platform Manager toggle:** Separate section with toggle switch and confirmation dialog. Uses `useSetPlatformManager` mutation. Shows warning when revoking, error when trying to revoke last PM.
    - **Delete button:** With confirmation dialog, shows warning text. Uses `useDeletePlatformUser` mutation. Redirects to users list on success.
    - **Audit trail:** Recent audit log entries targeting this user (filtered by entity type/id)

- [ ] **[PM-5.4.1]** Create PlatformAuditLog page
  - **File:** `frontend/src/pages/platform/PlatformAuditLog.tsx`
  - **Complexity:** M
  - **Dependencies:** [PM-4.4.1], [PM-4.3.4]
  - **Details:** Audit log viewer page. Components:
    - Filters bar: Platform Manager dropdown (searchable by name/email), Target Organization dropdown (searchable), Action type dropdown, Date range picker (from/to)
    - Log entries list using AuditLogEntry component
    - Each entry expandable to show JSON diff of before/after states
    - Pagination controls
    - Uses `usePlatformAuditLogs` hook

### Phase 6: Security & Audit

> **Status:** NOT STARTED
> **Estimated Effort:** 1-2 days
> **Dependencies:** Phase 2 complete

- [ ] **[PM-6.1.1]** Verify `is_platform_manager` is excluded from User `$fillable`
  - **File:** `app/Models/User.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Confirm that `is_platform_manager` is NOT in the `$fillable` array. This prevents mass assignment via `User::create()` or `$user->fill()`. Write a test to verify this.

- [ ] **[PM-6.1.2]** Verify normal user CRUD endpoints do not expose `is_platform_manager` for modification
  - **File:** `app/Http/Controllers/UserController.php` (or equivalent)
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Review the existing user create/update endpoints. Since `is_platform_manager` is not in `$fillable`, mass assignment protection handles this. However, verify there are no manual `$user->is_platform_manager = $request->input(...)` assignments. Also ensure the existing `StoreUserRequest` and `UpdateUserRequest` do not accept `is_platform_manager` as a valid field. Write a test to verify that sending `is_platform_manager: true` in a normal user update request does NOT change the flag.

- [ ] **[PM-6.1.3]** Ensure `is_platform_manager` is included in user API responses
  - **File:** `app/Http/Resources/UserResource.php` (or equivalent response formatting)
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** The `is_platform_manager` field should be included in user JSON responses so the frontend can read it. If the project uses API resources, add the field. If it uses `toArray()` directly, confirm it is included (it should be automatically since it is a column and not in `$hidden`).

- [ ] **[PM-6.2.1]** Audit logging integration test — verify all platform controller actions create audit records
  - **Complexity:** M
  - **Dependencies:** Phase 2 complete
  - **Details:** Write a feature test that calls each platform API endpoint and verifies a corresponding `platform_audit_logs` record is created with correct fields. See Testing Plan (Section 8) for details.

- [ ] **[PM-6.3.1]** Rate limiting for platform endpoints
  - **File:** `routes/platform.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.7.1]
  - **Details:** Apply rate limiting to platform routes. Use a separate rate limiter (e.g., `rate_limit_platform`) that is more permissive than the tenant-scoped limiter but still prevents abuse. Suggested: 120 requests per minute for platform endpoints.

### Phase 7: Testing

> **Status:** NOT STARTED
> **Estimated Effort:** 3-4 days
> **Dependencies:** Phases 1-3 complete (backend), Phase 5 partially complete (frontend)

- [ ] **[PM-7.1.1]** Unit test: OrganizationScope bypass mechanism
  - **File:** `tests/Unit/Scopes/OrganizationScopeBypassTest.php`
  - **Complexity:** M
  - **Dependencies:** [PM-1.4.1]
  - **Details:** Tests:
    - `test_bypass_allows_cross_org_queries()` — queries inside bypass callback return results from all orgs
    - `test_scope_applies_normally_outside_bypass()` — queries outside bypass are scoped
    - `test_nested_bypass_calls_work_correctly()` — nested bypass/un-bypass works with counter
    - `test_bypass_restores_scope_after_exception()` — exception inside callback still restores scope
    - `test_is_bypassed_returns_correct_state()` — static method reflects current state

- [ ] **[PM-7.1.2]** Unit test: EnsurePlatformManager middleware
  - **File:** `tests/Unit/Middleware/EnsurePlatformManagerTest.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.5.1]
  - **Details:** Tests:
    - `test_allows_platform_manager_through()` — request with PM user gets 200
    - `test_blocks_non_platform_manager()` — request with non-PM user gets 403
    - `test_blocks_unauthenticated_request()` — request with no user gets 403
    - `test_blocks_user_with_false_flag()` — user with `is_platform_manager = false` gets 403

- [ ] **[PM-7.1.3]** Unit test: User model isPlatformManager method
  - **File:** `tests/Unit/Models/UserPlatformManagerTest.php`
  - **Complexity:** S
  - **Dependencies:** [PM-1.2.1]
  - **Details:** Tests:
    - `test_is_platform_manager_returns_true_when_flag_is_true()`
    - `test_is_platform_manager_returns_false_when_flag_is_false()`
    - `test_is_platform_manager_returns_false_by_default()`
    - `test_is_platform_manager_not_mass_assignable()`

- [ ] **[PM-7.2.1]** Feature test: Platform Dashboard endpoint
  - **File:** `tests/Feature/Platform/PlatformDashboardTest.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.1.1]
  - **Details:** Tests:
    - `test_platform_manager_can_access_dashboard()` — returns 200 with correct stats structure
    - `test_non_platform_manager_cannot_access_dashboard()` — returns 403
    - `test_unauthenticated_cannot_access_dashboard()` — returns 401
    - `test_dashboard_returns_correct_counts()` — create known data, verify counts match

- [ ] **[PM-7.2.2]** Feature test: Platform Organization endpoints
  - **File:** `tests/Feature/Platform/PlatformOrganizationTest.php`
  - **Complexity:** L
  - **Dependencies:** [PM-2.2.1] through [PM-2.2.6]
  - **Details:** Tests:
    - `test_can_list_all_organizations()` — returns orgs from multiple tenants
    - `test_can_search_organizations_by_name()`
    - `test_can_filter_organizations_by_status()`
    - `test_can_sort_organizations()`
    - `test_pagination_works()`
    - `test_can_view_organization_detail()` — returns full detail with counts
    - `test_can_update_organization_settings()`
    - `test_can_change_organization_status_to_suspended()`
    - `test_can_change_organization_status_to_active()`
    - `test_can_soft_delete_organization()`
    - `test_cannot_delete_already_deleted_organization()`
    - `test_status_change_creates_audit_log()`
    - `test_non_platform_manager_cannot_access()` — all endpoints return 403

- [ ] **[PM-7.2.3]** Feature test: Platform User endpoints
  - **File:** `tests/Feature/Platform/PlatformUserTest.php`
  - **Complexity:** L
  - **Dependencies:** [PM-2.3.1] through [PM-2.3.10]
  - **Details:** Tests:
    - `test_can_list_all_users_across_organizations()`
    - `test_can_filter_users_by_organization()`
    - `test_can_list_users_for_specific_organization()`
    - `test_can_create_user_in_any_organization()`
    - `test_can_update_user_in_any_organization()`
    - `test_can_delete_user_in_any_organization()`
    - `test_cannot_delete_self()`
    - `test_cannot_delete_last_owner_of_organization()`
    - `test_can_set_platform_manager_flag()`
    - `test_can_revoke_platform_manager_flag()`
    - `test_cannot_revoke_last_platform_manager()`
    - `test_user_creation_creates_audit_log()`
    - `test_normal_user_crud_does_not_expose_platform_manager_flag()` — existing endpoints unaffected

- [ ] **[PM-7.2.4]** Feature test: Platform Audit Log endpoint
  - **File:** `tests/Feature/Platform/PlatformAuditLogTest.php`
  - **Complexity:** M
  - **Dependencies:** [PM-2.4.1]
  - **Details:** Tests:
    - `test_can_list_audit_logs()`
    - `test_can_filter_by_platform_manager()`
    - `test_can_filter_by_target_organization()`
    - `test_can_filter_by_action()`
    - `test_can_filter_by_date_range()`
    - `test_pagination_works()`
    - `test_default_sort_is_newest_first()`

- [ ] **[PM-7.3.1]** Feature test: Artisan commands
  - **File:** `tests/Feature/Commands/PlatformManagerCommandsTest.php`
  - **Complexity:** M
  - **Dependencies:** [PM-3.1.1], [PM-3.1.2], [PM-3.1.3]
  - **Details:** Tests:
    - `test_set_platform_manager_sets_flag_on_existing_user()`
    - `test_set_platform_manager_fails_for_nonexistent_user()`
    - `test_set_platform_manager_is_idempotent()`
    - `test_revoke_platform_manager_clears_flag()`
    - `test_revoke_platform_manager_fails_for_nonexistent_user()`
    - `test_revoke_platform_manager_refuses_to_revoke_last_manager()`
    - `test_create_platform_manager_creates_user_and_org()` (using artisan test helpers for interactive input)

- [ ] **[PM-7.4.1]** Regression test: existing tests still pass
  - **Complexity:** S
  - **Dependencies:** All Phase 1 items
  - **Details:** Run `./run-tests.sh` and confirm zero failures. This is a gating check that must pass before proceeding to any other testing.

- [ ] **[PM-7.5.1]** Frontend type-check: verify no TypeScript errors
  - **Complexity:** S
  - **Dependencies:** Phase 4, Phase 5
  - **Details:** Run `cd frontend && npm run type-check`. All platform-related TypeScript files must compile without errors.

- [ ] **[PM-7.5.2]** Frontend lint: verify no ESLint errors
  - **Complexity:** S
  - **Dependencies:** Phase 4, Phase 5
  - **Details:** Run `cd frontend && npm run lint`. All platform-related files must pass ESLint.

### Phase 8: Documentation & Polish

> **Status:** NOT STARTED
> **Estimated Effort:** 1 day
> **Dependencies:** Phases 1-7 complete

- [ ] **[PM-8.1.1]** Add platform manager section to README or internal docs
  - **File:** Documentation (location TBD)
  - **Complexity:** S
  - **Dependencies:** All implementation complete
  - **Details:** Document: what platform manager is, how to bootstrap, available commands, API endpoints overview. This is internal developer documentation, not end-user docs.

- [ ] **[PM-8.1.2]** Update AGENTS.md with platform manager architecture notes
  - **File:** `AGENTS.md`
  - **Complexity:** S
  - **Dependencies:** All implementation complete
  - **Details:** Add a section describing the platform manager architecture pattern for future AI agents: the bypass mechanism, the separate route group, the audit logging requirement.

- [ ] **[PM-8.2.1]** Run full test suite and lint checks
  - **Complexity:** S
  - **Dependencies:** All implementation and tests complete
  - **Details:** Final verification run: `./run-tests.sh`, `vendor/bin/pint --dirty`, `cd frontend && npm run lint && npm run type-check && npm run build`. All must pass.

- [ ] **[PM-8.2.2]** Manual smoke test of bootstrap flow
  - **Complexity:** S
  - **Dependencies:** All implementation complete
  - **Details:** Manually verify: run `php artisan opbx:create-platform-manager`, log in as that user, navigate to `/ui/platform/dashboard`, verify organizations are listed, verify audit log records are created.

---

