# Supervisor Role

> **Last Updated**: 2026-07-10
> **Status**: ACTIVE — Read-only monitoring role
> **Depends On**: User Management, Call Detail Records, Live Calls, WebSocket Real-Time
> **Location**: Merged into `feature/snoop-and-barge` (no separate feature branch).

---

## Overview

A read-only monitoring role parallel to `reporter`. A `supervisor` is explicitly assigned to one or more users and/or ring groups, and can only view data for those assigned resources. Supervisors cannot manage configuration, disconnect calls, export data, or invite users.

---

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Enums/UserRole.php` | `SUPERVISOR` enum case and permission helpers |
| `app/Models/User.php` | `supervisedUsers`, `supervisedRingGroups`, `isSupervisor()` helpers |
| `app/Services/Supervisor/SupervisorFilterService.php` | Collects user/extension/ring-group identifiers for filtering |
| `app/Http/Controllers/Api/SupervisorAssignmentController.php` | Assignment CRUD for Supervisors |
| `app/Http/Controllers/Api/SupervisorDashboardController.php` | Supervisor-scoped dashboard |
| `app/Http/Requests/Supervisor/StoreAssignmentsRequest.php` | Validation for assignment updates |
| `app/Http/Resources/SupervisorAssignmentResource.php` | Response shape: `supervisor_id`, `user_ids`, `ring_group_ids`, `users`, `ring_groups` |
| `app/Policies/UserPolicy.php` | `viewAny`/`view`/`assignAsSupervisor` rules |
| `app/Policies/ExtensionPolicy.php` | Read-only access to assigned users' extensions |
| `app/Policies/RingGroupPolicy.php` | Read-only access to assigned ring groups |
| `app/Policies/CallDetailRecordPolicy.php` | Assignment scope for CDR `view` |
| `app/Http/Controllers/Api/UsersController.php` | Filters user list for Supervisors |
| `app/Http/Controllers/Api/SessionUpdateController.php` | `?supervisor=true` filter for live calls |
| `app/Http/Controllers/Api/CallDetailRecordController.php` | Supervisor scope for `index`/`export`/`statistics`/`show` |
| `app/Http/Controllers/Api/AuthController.php` | Supervisor-specific Sanctum abilities |
| `routes/channels.php` | `org.{orgId}` channel denies Supervisors to avoid unassigned event leaks |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/components/Users/UserForm.tsx` | Role enum includes `supervisor` |
| `frontend/src/pages/UsersComplete.tsx` | User management page; uses extracted `SupervisorAssignmentDialog` for assignments |
| `frontend/src/pages/Supervisors.tsx` | Standalone supervisor management page (Owner/PBX Admin only) |
| `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx` | Reusable assignment dialog for users and ring groups |
| `frontend/src/components/Supervisors/CreateSupervisorDialog.tsx` | Create-user dialog pre-filled with role `supervisor` |
| `frontend/src/services/supervisorAssignments.service.ts` | Assignment API client |
| `frontend/src/components/Layout/Sidebar.tsx` | Supervisor sees Dashboard, Live Calls, Call Logs, Users, Ring Groups; Owner/PBX Admin also see Supervisors |
| `frontend/src/pages/Dashboard.tsx` | Supervisor dashboard view |
| `frontend/src/services/dashboard.service.ts` | `getSupervisorDashboard()` |
| `frontend/src/pages/LiveCalls.tsx` | `?supervisor=true` filter; Actions column shows Spy/Whisper/Barge/Disconnect for Owner and Supervisor |
| `frontend/src/services/sessionUpdates.service.ts` | `supervisor` query param support |
| `frontend/src/pages/CallLogs.tsx` | `?supervisor=true` filter; export hidden |
| `frontend/src/services/cdr.service.ts` | `?supervisor=true` CDR query param support |

---

## Database

- `users.role` enum now includes `supervisor`.
- `supervisor_user_assignments`: `supervisor_id`, `user_id`, `organization_id`.
- `supervisor_ring_group_assignments`: `supervisor_id`, `ring_group_id`, `organization_id`.

---

## API Routes

| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/api/v1/supervisors/{user}/assignments` | Fetch current assignments |
| PUT | `/api/v1/supervisors/{user}/assignments` | Update assignments |
| GET | `/api/v1/dashboard/supervisor` | Supervisor dashboard |
| GET | `/api/v1/session-updates/active?supervisor=true` | Filtered live calls |
| GET | `/api/v1/call-detail-records?supervisor=true` | Filtered CDR list |

---

## Filtering Logic

`SupervisorFilterService::resourceIdentifiers()` returns a flat array containing:
- Assigned user IDs
- Assigned users' extension numbers
- Assigned ring group IDs
- Assigned ring groups' extension numbers

Live calls filter on `caller_id`/`destination`; CDRs filter on `from`/`to`.

---

## Assignment Dialog

- Supervisor assignments are edited through the reusable `SupervisorAssignmentDialog`.
- The dialog shows **two columns side-by-side** (stacked on mobile):
  - **Left column:** a native `<select multiple>` box listing all **PBX User** role users. Multiple selection is enabled with Ctrl/Cmd or Shift.
  - **Right column:** a native `<select multiple>` box listing all **ring groups**. Multiple selection is enabled with Ctrl/Cmd or Shift.
- The dialog reads the flat `user_ids` and `ring_group_ids` arrays from `SupervisorAssignmentResource` to pre-populate selected users and ring groups.
- Clicking **Save Assignments** calls `PUT /api/v1/supervisors/{user}/assignments` and replaces the supervisor's assignments with the selected user IDs and ring group IDs.
- Only users whose role is `pbx_user` may be supervised. Enforced server-side in `SupervisorAssignmentController::validateSupervisableUsers()` — any non-`pbx_user` id (owner, pbx_admin, reporter, supervisor) or self-assignment returns HTTP 422.

---

## Security Notes

- Supervisors cannot manage users, extensions, ring groups, or export data.
- Disconnecting a call is **Owner-only** — `SessionUpdatePolicy::disconnect` -> `canManageOrganization()` (owner only), enforced by `authorize('disconnect')` in `SessionUpdateController::disconnectSession()` before any Cloudonix call. Supervisors and PBX Admins cannot disconnect.
- WebSocket org channel is blocked for Supervisors; they rely on HTTP polling.
- All Supervisor-scoped endpoints enforce tenant isolation via `OrganizationScope`.
- CDR `show`, `export`, and `statistics` are scoped to assigned resources.

---

## Related Modules

- [User Management](user-management.md)
- [Live Calls](live-calls.md)
- [Call Logs](call-logs.md)
- [Call Detail Records](call-detail-records.md)
- [WebSocket Real-Time](websocket-realtime.md)
