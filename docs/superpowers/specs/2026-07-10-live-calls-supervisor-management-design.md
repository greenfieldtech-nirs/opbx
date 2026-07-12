# Live Calls Snoop Actions & Supervisor Management Page

> **Date:** 2026-07-10  
> **Status:** Design approved, ready for implementation  
> **Scope:** Frontend-only UI changes for two related Supervisor experience improvements

---

## 1. Summary

This specification covers two frontend changes in the OpBX React application:

1. **Live Calls — Actions column redesign:** Add three new monitoring actions (Spy, Whisper, Barge) alongside the existing Disconnect action, all rendered as icon-only buttons with tooltips. Restrict the Actions column to **Owner** and **Supervisor** roles only.
2. **New Supervisor Management page:** Add a standalone page at `/ui/supervisors` where **Owner** and **PBX Admin** can view all supervisors, see their assignments at a glance, and edit those assignments without navigating through the user-edit flow.

Both changes are UI-only; the snoop actions (Spy/Whisper/Barge) are non-functional placeholders.

---

## 2. Task 1: Live Calls Actions Column

### 2.1 File

`frontend/src/pages/LiveCalls.tsx`

### 2.2 Role gating

Current code uses `isReadOnly` to hide the Actions column for `reporter`, `pbx_user`, and `supervisor`. Introduce a new, explicit permission flag:

```ts
const canUseLiveCallActions = ['owner', 'supervisor'].includes(currentUser?.role);
```

The Actions column renders **only** when `canUseLiveCallActions` is true.

| Role | Sees Actions column |
|------|---------------------|
| Owner | Yes |
| Supervisor | Yes |
| PBX Admin | No |
| PBX User | No |
| Reporter | No |

The top-level **Disconnect All Calls** and **Clear Stale** buttons remain controlled by `!isReadOnly` (Owner and PBX Admin) and are **not** changed.

### 2.3 Button order and icons

From left to right in the Actions cell:

| Action | lucide-react icon | Tooltip | Variant |
|--------|-------------------|---------|---------|
| Spy | `Headphones` | Spy | `ghost`, disabled |
| Whisper | `Mic` | Whisper | `ghost`, disabled |
| Barge | `Phone` | Barge | `ghost`, disabled |
| Disconnect | `PhoneOff` | Disconnect | `ghost` with destructive classes (`text-destructive hover:text-destructive hover:bg-destructive/10`) |

All buttons are **icon-only** with size `sm` and class `h-8 w-8 p-0`.

### 2.4 Behavior

- **Spy, Whisper, Barge:** Render as disabled buttons with tooltips. They must not trigger any API calls or navigation. Add `aria-label` matching the tooltip text.
- **Disconnect:** Keep existing behavior — `window.confirm(...)` then call `sessionUpdatesService.disconnectSession(sessionId)`, apply optimistic local removal, and update the `recentlyDisconnected` set.

### 2.5 Implementation notes

- Remove the existing text-based Disconnect `<Button>` and replace it with the four icon buttons.
- Use the existing `@/components/ui/tooltip` components.
- Ensure `e.stopPropagation()` is called on each button so row clicks are not triggered.

---

## 3. Task 2: Supervisor Management Page

### 3.1 Route and navigation

- **Route:** `/ui/supervisors`
- **Sidebar:** Add an entry under the **PBX Configuration** section, immediately after **Users**.
  - Label: `Supervisors`
  - Icon: `codicon-account` (reuse the Users icon) or `codicon-shield` if a distinct icon is preferred
  - Roles: `['owner', 'pbx_admin']`
- **Route registration:** Add a lazy-loaded route in `frontend/src/router.tsx`:

  ```tsx
  const Supervisors = lazy(() => import('@/pages/Supervisors'));
  ```

  ```tsx
  {
    path: 'supervisors',
    element: <Supervisors />,
  }
  ```

### 3.2 Page layout

Path: `frontend/src/pages/Supervisors.tsx`

Structure follows the existing list-page pattern (e.g., `UsersComplete.tsx`, `RingGroups.tsx`):

```text
┌─────────────────────────────────────────────────────────┐
│  Supervisors                    [Create Supervisor]     │
│  Manage supervisors and their monitoring assignments  │
├─────────────────────────────────────────────────────────┤
│  [Search by name or email...]  [Status: all ▼]        │
├─────────────────────────────────────────────────────────┤
│  StandardDataTable                                      │
│  Name | Email | Status | Assigned Users | Assigned RG  │
└─────────────────────────────────────────────────────────┘
```

### 3.3 Table columns

Use `StandardDataTable` with `canView={false}`, `canEdit={false}`, `canDelete={false}` and add a custom `Actions` column.

| Column | Content |
|--------|---------|
| Name / Identity | `StandardDataTable` identity column: `User` icon, supervisor name, email as secondary text. |
| Email | `user.email` |
| Status | `Badge` using `getStatusColor(user.status)` / `getStatusDisplayName(user.status)` |
| Assigned Users | First two assigned user names as small chips, then `+N` if more exist. |
| Assigned Ring Groups | First two assigned ring group names as small chips, then `+N` if more exist. |
| Actions | `Edit Assignments` icon-only button (`Users` icon, tooltip). |

### 3.4 Filters

- **Search input:** client-side filter on `user.name` and `user.email`.
- **Status filter:** `Select` dropdown with values `all`, `active`, `inactive`.

### 3.5 Data fetching

- Fetch the user list with `usersService.getAll({ per_page: 1000 })` and filter client-side by `role === 'supervisor'`.
- Fetch assignments per visible supervisor using TanStack Query `useQueries` keyed by `['supervisor-assignments', supervisorId]`.
- This avoids backend changes for v1. If supervisor counts become a performance issue, a backend endpoint can be added later.

### 3.6 Create Supervisor flow

- Clicking **Create Supervisor** opens a dialog on the current page.
- The dialog reuses `frontend/src/components/Users/UserForm.tsx` with `defaultValues` pre-filled to `role: 'supervisor'` and `status: 'active'`.
- On success, invalidate the `['users']` query and optionally open the assignment dialog for the newly created supervisor.

### 3.7 Edit Assignments flow

- Extract the existing `SupervisorAssignmentDialog` from `frontend/src/pages/UsersComplete.tsx` into a new reusable component at `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx`.
- The new page imports this component and opens it when the user clicks **Assign Resources** in the Actions column.
- The Users page should also be updated to import the extracted component instead of its inline copy.

### 3.8 Assignment dialog layout

- The dialog displays **two columns side-by-side** (stacked on mobile):
  - **Left column:** a native `<select multiple>` box listing all users with the **PBX User** role. Multiple selection is enabled with Ctrl/Cmd or Shift.
  - **Right column:** a native `<select multiple>` box listing all **ring groups**. Multiple selection is enabled with Ctrl/Cmd or Shift.
- Both select boxes are sized to `h-[300px]` so they are scrollable and show a meaningful number of options at once.
- Only users whose `role` is `pbx_user` are shown in the left select box. Owners, PBX Admins, Supervisors, and Reporters are not selectable as supervised users.
- The **Save Assignments** button reads the currently selected options from both select boxes and calls `PUT /api/v1/supervisors/{userId}/assignments`.

### 3.9 Empty state

When no supervisors exist, render the `EmptyState` component:

- **Icon:** `Users` (lucide)
- **Title:** "No supervisors yet"
- **Description:** "Supervisors can monitor assigned users and ring groups. Create your first supervisor to get started."
- **Primary action:** **Create Supervisor** button (shown only when the current user has create permissions — Owner and PBX Admin).

### 3.10 Access control

- The sidebar item is only visible to Owner and PBX Admin.
- Inside the page, add an early guard:

  ```tsx
  if (!['owner', 'pbx_admin'].includes(currentUser?.role)) {
    return <Navigate to="/ui/dashboard" replace />;
  }
  ```

---

## 4. Files to modify and create

### Create

| File | Purpose |
|------|---------|
| `frontend/src/pages/Supervisors.tsx` | New Supervisor Management page. |
| `frontend/src/components/Supervisors/SupervisorAssignmentDialog.tsx` | Extracted, reusable assignment dialog. |
| `frontend/src/components/Supervisors/CreateSupervisorDialog.tsx` | User creation dialog pre-filled for Supervisor. |

### Modify

| File | Purpose |
|------|---------|
| `frontend/src/pages/LiveCalls.tsx` | Update role gating and Actions column. |
| `frontend/src/pages/UsersComplete.tsx` | Replace inline `SupervisorAssignmentDialog` with the extracted component. |
| `frontend/src/router.tsx` | Register `/ui/supervisors` route. |
| `frontend/src/components/Layout/Sidebar.tsx` | Add `Supervisors` sidebar entry. |

---

## 5. API endpoints used

| Purpose | Endpoint |
|---------|----------|
| List supervisors | `GET /api/v1/users` (filter client-side by `role === 'supervisor'`) |
| Fetch assignments | `GET /api/v1/supervisors/{userId}/assignments` |
| Update assignments | `PUT /api/v1/supervisors/{userId}/assignments` |
| Create supervisor | `POST /api/v1/users` via `usersService.create` |

---

## 6. Non-functional placeholders

Spy, Whisper, and Barge are intentionally non-functional in this iteration. They render as disabled icon-only buttons with tooltips. No backend endpoints, CXML verbs, or WebSocket integrations are added for these actions.

---

## 7. Testing considerations

- Unit tests are not required for this UI-only change.
- Manual verification checklist:
  - Live Calls Actions column is visible only for Owner and Supervisor.
  - Disconnect is icon-only with tooltip and still works.
  - Spy/Whisper/Barge are disabled and have tooltips.
  - `/ui/supervisors` is accessible from the sidebar for Owner and PBX Admin only.
  - Supervisor Management page lists only users with role `supervisor`.
  - Editing assignments from the new page updates the Supervisor's view correctly.
  - The extracted assignment dialog still works from the Users page.
  - Frontend type-check and build pass.

---

## 8. Out of scope

- Backend changes for aggregated supervisor assignment counts.
- Functional implementation of Spy, Whisper, or Barge.
- Separate role-based route wrapper component.
- User detail sheet on the Supervisors page.

---

## 9. Decisions log

| Decision | Rationale |
|----------|-----------|
| Restrict Actions column to Owner and Supervisor only | Direct requirement from the user. |
| Keep Disconnect All available to Owner and PBX Admin | User only requested changes to the per-row Actions column; bulk actions were not discussed. |
| Use disabled icon buttons for Spy/Whisper/Barge | User asked for UI only; disabled state prevents accidental clicks and clearly signals they are placeholders. |
| Fetch assignment counts via frontend `useQueries` | Avoids backend changes and keeps the implementation focused on UI. |
| Extract `SupervisorAssignmentDialog` from `UsersComplete.tsx` | Eliminates duplication between the Users page and the new Supervisors page. |
| Create Supervisor via dialog on current page | Faster user flow; no need to modify the Users page for query parameters. |
| Skip View Details on the Supervisors page | Not required for assignment management; can be added later. |
