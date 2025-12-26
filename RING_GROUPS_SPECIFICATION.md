# Ring Groups Management - Detailed Specification

## 1. Overview

### Purpose
Ring Groups allow multiple extensions to be called simultaneously or sequentially when a call is routed to the group. This enables call distribution across teams (sales, support, etc.) without requiring manual call transfer.

### Key Concepts
- A Ring Group contains multiple extensions as members
- Each member has a priority (for sequential strategies)
- Different ringing strategies determine how calls are distributed
- Timeout controls how long to ring before fallback
- Fallback actions handle unanswered calls

---

## 2. Data Model

### Ring Group Fields

#### Basic Information
- **ID**: Unique identifier (auto-generated)
- **Organization ID**: Tenant isolation
- **Name**: Human-readable name (required, unique per organization)
  - Example: "Sales Team", "Support Department", "After Hours Team"
  - Max length: 255 characters
  - Validation: Required, unique within organization

- **Description**: Optional text description
  - Max length: 1000 characters
  - Helps identify purpose/usage

- **Status**: Active/Inactive enum
  - Active: Ring group can receive calls
  - Inactive: Ring group temporarily disabled

#### Ringing Strategy
- **Strategy**: How calls are distributed (required)
  - **Simultaneous (Ring All)**: All members ring at the same time, first to answer gets the call
  - **Round Robin**: Calls distributed evenly, rotating through members
  - **Sequential (Priority Order)**: Ring members one at a time based on priority

#### Member Configuration
- **Members**: Array of extension assignments
  - Extension ID (required)
  - **Extension Type Constraint**: ONLY extensions of type "user" (PBX User) can be members
    - Virtual extensions (type: "virtual") cannot be ring group members
    - Queue extensions (type: "queue") cannot be ring group members
    - Only real user extensions that can answer calls are allowed
  - Priority/Order (1-100, for sequential strategy)
  - Can include the same extension in multiple ring groups
  - Minimum: 1 member required
  - Maximum: 50 members per group
  - Validation: Extension must be type "user", status "active", and in same organization

#### Timeout & Fallback
- **Ring Timeout**: How long to ring before fallback (in seconds)
  - Default: 30 seconds
  - Range: 10-120 seconds
  - Applies per member in sequential mode, total time in simultaneous mode

- **Fallback Action**: What happens if no one answers
  - **Voicemail**: Send to voicemail (with optional extension owner's mailbox)
  - **Extension**: Forward to specific extension
  - **Hangup**: End the call
  - **Repeat**: Start ringing cycle again (max 3 times)

- **Fallback Extension ID**: Required if fallback action is "Extension"
  - Must be a valid, active extension

#### Timestamps
- **Created At**: ISO 8601 timestamp
- **Updated At**: ISO 8601 timestamp

---

## 3. User Roles & Permissions

### Owner
- ✅ View all ring groups
- ✅ Create ring groups
- ✅ Edit all ring groups
- ✅ Delete ring groups
- ✅ Manage members

### PBX Admin
- ✅ View all ring groups
- ✅ Create ring groups
- ✅ Edit all ring groups
- ✅ Delete ring groups
- ✅ Manage members

### PBX User
- ✅ View ring groups they belong to
- ❌ Cannot create/edit/delete

### Reporter
- ✅ View ring groups (read-only)
- ❌ Cannot modify

---

## 4. Page Layout & UI Components

### Main Ring Groups Page

#### Header Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Ring Groups                                      [+ Add Ring Group]│
│ Manage call distribution groups                                   │
└─────────────────────────────────────────────────────────────────┘
```

#### Filters & Search Bar
```
┌─────────────────────────────────────────────────────────────────┐
│ [🔍 Search ring groups...]  [Strategy ▼]  [Status ▼]  [Members ▼]│
└─────────────────────────────────────────────────────────────────┘
```

**Filters:**
- **Search**: Searches name and description (debounced 300ms)
- **Strategy Dropdown**: All / Simultaneous / Round Robin / Sequential
- **Status Dropdown**: All / Active / Inactive
- **Members Filter**: All / Has Unassigned Slots / Fully Configured

#### Ring Groups Table

**Columns:**
1. **Group Name** (sortable)
   - Primary identifier
   - Click to open detail sheet

2. **Strategy** (sortable)
   - Badge with icon:
     - Simultaneous: "Ring All" (blue badge, users icon)
     - Round Robin: "Round Robin" (purple badge, rotate icon)
     - Sequential: "Sequential" (green badge, list icon)

3. **Members** (sortable by count)
   - Shows count: "5 members"
   - Hover to see member names tooltip

4. **Timeout**
   - "30s" format
   - Shows ring duration

5. **Fallback**
   - Text description: "Voicemail", "→ Ext 101", "Hangup"
   - Icon based on type

6. **Status** (sortable)
   - Badge: Active (green) / Inactive (gray)

7. **Actions**
   - Dropdown menu (3-dot icon):
     - View Details
     - Edit
     - Duplicate (creates copy with " - Copy" suffix)
     - Delete (with confirmation)

**Empty State:**
```
┌─────────────────────────────────────────────────────────────────┐
│                          📞                                       │
│                 No Ring Groups Found                             │
│                                                                   │
│   Get started by creating your first ring group to distribute    │
│              calls across multiple extensions                    │
│                                                                   │
│                    [+ Add Ring Group]                            │
└─────────────────────────────────────────────────────────────────┘
```

**Loading State:**
- Skeleton rows (5 rows)
- Shimmer effect on each cell

**Pagination:**
- Bottom of table
- Shows: "Showing 1 to 25 of 47 ring groups"
- [Previous] Page 2 of 3 [Next]
- Default: 25 per page

---

## 5. Create/Edit Ring Group Dialog

### Dialog Layout (Max Width: 800px, Scrollable)

#### Basic Information Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Group Name *                                                     │
│ [Sales Team                                          ]           │
│                                                                   │
│ Description                                                       │
│ [Main sales team for inbound leads                  ]           │
│ [                                                    ]           │
│                                                                   │
│ Status                                                           │
│ ( ) Active  (•) Inactive                                        │
└─────────────────────────────────────────────────────────────────┘
```

#### Ringing Strategy Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Ringing Strategy *                                               │
│                                                                   │
│ [📻 Simultaneous (Ring All)                           ] ▼        │
│                                                                   │
│ ℹ️ All members ring at the same time. First to answer gets       │
│    the call.                                                     │
└─────────────────────────────────────────────────────────────────┘
```

**Strategy Options with Descriptions:**

1. **Simultaneous (Ring All)**
   - Icon: Multiple users
   - Description: "All members ring at the same time. First to answer gets the call."
   - Best for: Small teams, urgent calls

2. **Round Robin**
   - Icon: Circular arrows
   - Description: "Calls distributed evenly across members in rotation. Balances workload."
   - Best for: Fair distribution, support teams

3. **Sequential (Priority Order)**
   - Icon: Numbered list
   - Description: "Ring members one at a time based on priority order. Higher priority rings first."
   - Best for: Hierarchical teams, escalation paths

#### Members Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Group Members * (1 minimum)                                      │
│                                                                   │
│ ℹ️ Only PBX User extensions can be added to ring groups          │
│                                                                   │
│ [+ Add Member]                                                   │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 1. [Extension 101 - John Doe          ▼] [Priority: 1  ]❌ │ │
│ │    Dropdown: User extensions only (type=user, status=active)│ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 2. [Extension 102 - Jane Smith        ▼] [Priority: 2  ]❌ │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 3. [Extension 103 - Bob Johnson       ▼] [Priority: 3  ]❌ │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ ⚠️ Priority only matters for Sequential strategy                 │
│ ⚠️ Virtual/Queue extensions cannot be added                      │
└─────────────────────────────────────────────────────────────────┘
```

**Member Management:**
- Click "+ Add Member" to add new row
- Extension dropdown populated from database with filtered extensions:
  - **Type Filter**: ONLY extensions of type "user" (PBX User extensions)
  - **Status Filter**: Only "active" extensions
  - **Organization Filter**: Only extensions in current user's organization
  - **Exclusion Filter**: Extensions already in this ring group are excluded
  - Display format: "Ext ### - User Name" or "Ext ### (Unassigned)"
- Priority field (1-100) only enabled for Sequential strategy
- Drag handle (⋮⋮) to reorder members (auto-updates priority)
- Remove button (❌) removes member from list
- Validation:
  - Cannot add same extension twice
  - Must have at least 1 member
  - Extension must be type "user" (not "virtual" or "queue")
  - Extension must be active
  - Priority must be unique (auto-adjusts on drag)
- **Special Case - No Available Extensions**:
  - If no user extensions exist or all are already added:
    - Disable "+ Add Member" button
    - Show helper text: "No available user extensions. Create user extensions first."
    - Cannot save ring group without at least one member

#### Timeout & Fallback Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Ring Timeout *                                                   │
│ [30] seconds                                                     │
│ ℹ️ Range: 10-120 seconds                                         │
│                                                                   │
│ Fallback Action *                                                │
│ [Voicemail                                        ▼]             │
│                                                                   │
│ [Send to extension's voicemail                   ]  (conditional)│
│ or                                                               │
│ [Forward to Extension: 104 - Support             ]  (conditional)│
└─────────────────────────────────────────────────────────────────┘
```

**Fallback Options:**
1. **Voicemail**: Shows checkbox "Use owner's voicemail box"
2. **Extension**: Shows extension dropdown (required)
3. **Hangup**: No additional fields
4. **Repeat**: Shows "Max attempts" dropdown (1-3)

#### Dialog Footer
```
┌─────────────────────────────────────────────────────────────────┐
│                                          [Cancel] [Save Changes] │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**
- Cancel: Closes dialog, discards changes
- Save Changes: Validates and submits
  - Shows "Saving..." during mutation
  - Disables both buttons during save

---

## 6. Validation Rules

### Form Validation

#### Name Field
- ❌ Cannot be empty
- ❌ Cannot exceed 255 characters
- ❌ Must be unique within organization
- ✅ Toast error: "Ring group name is required"
- ✅ Toast error: "A ring group with this name already exists"

#### Members
- ❌ Cannot have zero members
- ❌ Cannot add duplicate extensions
- ❌ Extensions must be type "user" (PBX User extensions only)
- ❌ Extensions must be active status
- ❌ Priority must be between 1-100
- ✅ Toast error: "At least one member is required"
- ✅ Toast error: "Extension 101 is already in this group"
- ✅ Toast error: "Only user extensions can be added to ring groups"
- ✅ Dropdown shows: Only user-type, active extensions from database

#### Timeout
- ❌ Must be between 10-120 seconds
- ❌ Must be numeric
- ✅ Toast error: "Timeout must be between 10 and 120 seconds"

#### Fallback Extension
- ❌ Required when fallback action is "Extension"
- ❌ Must be active extension
- ❌ Cannot be in a circular reference loop
- ✅ Toast error: "Please select a fallback extension"
- ✅ Toast error: "Fallback extension creates a loop"

---

## 7. Detail Sheet (Side Panel)

### Opens when clicking a ring group row

**Layout: 600px wide, full height, slides in from right**

#### Header
```
┌─────────────────────────────────────────────────────────────────┐
│ Sales Team                                    [Active] [Edit] [×]│
│ Main sales team for inbound leads                               │
└─────────────────────────────────────────────────────────────────┘
```

#### Overview Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Strategy                                                         │
│ 📻 Simultaneous (Ring All)                                       │
│                                                                   │
│ Timeout                                                          │
│ 30 seconds                                                       │
│                                                                   │
│ Fallback                                                         │
│ → Voicemail                                                      │
└─────────────────────────────────────────────────────────────────┘
```

#### Members List Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Group Members (5)                                                │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 📞 Ext 101 - John Doe                    Priority: 1        │ │
│ │    Active                                                   │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 📞 Ext 102 - Jane Smith                  Priority: 2        │ │
│ │    Active                                                   │ │
│ └─────────────────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 📞 Ext 103 - Bob Johnson                 Priority: 3        │ │
│ │    Active                                                   │ │
│ └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

#### DIDs Using This Group Section (Future)
```
┌─────────────────────────────────────────────────────────────────┐
│ Assigned DIDs (2)                                                │
│                                                                   │
│ • +1 (555) 123-4567 - Main Sales Line                           │
│ • +1 (555) 987-6543 - Overflow Line                             │
└─────────────────────────────────────────────────────────────────┘
```

#### Metadata Section
```
┌─────────────────────────────────────────────────────────────────┐
│ Created                                                          │
│ January 15, 2025 at 10:30 AM                                    │
│                                                                   │
│ Last Updated                                                     │
│ January 20, 2025 at 3:45 PM                                     │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Delete Confirmation Dialog

```
┌─────────────────────────────────────────────────────────────────┐
│ Delete Ring Group?                                               │
│                                                                   │
│ Are you sure you want to delete "Sales Team"?                   │
│                                                                   │
│ ⚠️ Warning: This ring group is currently assigned to 2 DIDs:     │
│   • +1 (555) 123-4567                                           │
│   • +1 (555) 987-6543                                           │
│                                                                   │
│ These DIDs will need to be reconfigured.                        │
│                                                                   │
│ This action cannot be undone.                                   │
│                                                                   │
│                                          [Cancel] [Delete Group] │
└─────────────────────────────────────────────────────────────────┘
```

**Delete Button:**
- Red/destructive styling
- Shows "Deleting..." during mutation
- Disabled during deletion

---

## 9. Search & Filter Behavior

### Search
- **Fields searched**: name, description
- **Debounced**: 300ms delay
- **Case insensitive**
- **Partial match**: "sales" matches "Sales Team" and "Wholesale"
- **Resets to page 1** when search changes

### Strategy Filter
- **Options**: All Strategies, Simultaneous, Round Robin, Sequential
- **Default**: All Strategies
- **Server-side filtering**

### Status Filter
- **Options**: All Statuses, Active, Inactive
- **Default**: All Statuses
- **Server-side filtering**

### Members Filter (Optional/Future)
- **Options**: All, 1-5 members, 6-10 members, 11+ members
- **Helps find**: Small vs large groups

---

## 10. Sorting

**Sortable Columns:**
1. Group Name (default: ascending)
2. Strategy
3. Members (by count)
4. Status
5. Created At
6. Updated At

**Behavior:**
- Click column header to toggle sort
- Shows arrow indicator (↑↓)
- Server-side sorting
- Maintains across pagination

---

## 11. Special Features

### Duplicate Ring Group
- Creates exact copy with " - Copy" suffix
- Opens edit dialog with copied data
- User can modify before saving
- New ID generated on save

### Drag-to-Reorder Members
- Visual drag handle (⋮⋮) on each member row
- Dragging updates priority automatically
- Only enabled for Sequential strategy
- Shows visual feedback during drag

### Smart Priority Assignment
- Adding new member: assigns next available priority
- Removing member: shifts priorities down
- Reordering: recalculates all priorities
- User can manually override

### Extension Status Indicators
- In member list, show extension status
- Gray out inactive extensions
- Warning if extension becomes inactive after assignment

---

## 12. Error States & Messages

### API Errors
- **Network error**: "Unable to load ring groups. Please check your connection."
- **Permission denied**: "You don't have permission to manage ring groups."
- **Not found**: "Ring group not found. It may have been deleted."

### Validation Errors
- **Inline field errors**: Red border + error text below field
- **Toast notifications**: For form-level errors
- **Summary errors**: List all validation errors at top of form

### Conflict Errors
- **Name conflict**: "Ring group 'Sales Team' already exists"
- **Extension conflict**: "Extension 101 is already in this group"
- **Circular reference**: "Fallback creates a routing loop"

---

## 13. Performance Considerations

### Pagination
- **Default**: 25 items per page
- **Options**: 10, 25, 50, 100
- **Lazy loading**: Only fetch current page
- **Total count**: Display total ring groups

### Optimistic Updates
- **Create**: Add to list immediately, rollback on error
- **Update**: Update in place, rollback on error
- **Delete**: Remove immediately, restore on error

### Caching
- **React Query**: 5-minute stale time
- **Invalidate**: On create/update/delete
- **Background refetch**: Every 5 minutes if page active

---

## 14. Accessibility

### Keyboard Navigation
- **Tab**: Navigate through form fields
- **Enter**: Submit form / open dialog
- **Escape**: Close dialog / cancel
- **Arrow keys**: Navigate table rows

### Screen Reader Support
- **ARIA labels**: All buttons and inputs
- **Role attributes**: Table, dialog, alert
- **Status announcements**: "Ring group created successfully"

### Focus Management
- **Dialog open**: Focus first field
- **Dialog close**: Return focus to trigger
- **Error**: Focus first invalid field

---

## 15. Mobile Responsiveness

### Table on Mobile
- **Cards instead of table**
- Stack information vertically
- Show essential info only
- "View More" button for details

### Dialog on Mobile
- **Full-screen modal** instead of dialog
- Sticky header with title and close
- Scrollable content area
- Sticky footer with actions

### Touch Interactions
- **Larger tap targets**: 44x44px minimum
- **Swipe to delete**: Alternative to dropdown menu
- **Pull to refresh**: Reload ring groups list

---

## 16. Future Enhancements (Not MVP)

### Advanced Features
- **Call recording**: Enable/disable recording for ring group
- **Announcement**: Play message before ringing
- **Music on hold**: Custom hold music
- **Call whisper**: Message to agent when answering
- **Wrap-up time**: Delay before agent receives next call
- **Skill-based routing**: Route based on agent skills
- **Time-based routing**: Different members for different hours
- **Overflow groups**: Cascade to backup group if all busy

### Analytics
- **Call statistics**: Calls answered, missed, abandoned
- **Member performance**: Individual answer rates
- **Average wait time**: Time to answer
- **Busiest hours**: Peak call times

### Integrations
- **CRM sync**: Push call data to CRM
- **Calendar integration**: Respect agent availability
- **Chat fallback**: Offer chat if no answer

---

## 17. Technical Notes

### API Endpoints

**Ring Groups:**
- `GET /api/v1/ring-groups` - List (paginated, filtered)
- `POST /api/v1/ring-groups` - Create
- `GET /api/v1/ring-groups/{id}` - Show
- `PUT /api/v1/ring-groups/{id}` - Update
- `DELETE /api/v1/ring-groups/{id}` - Delete

**Extensions (for member selection):**
- `GET /api/v1/extensions?type=user&status=active` - Get available extensions
  - **Required filters**: `type=user` (only PBX User extensions)
  - **Required filters**: `status=active` (only active extensions)
  - **Auto-filtered**: By organization_id (tenant-scoped)
  - **Frontend exclusion**: Remove extensions already in current ring group
  - **Response format**: Array of extensions with id, extension_number, user name
  - **Used for**: Member selection dropdown in create/edit dialog

### Database Schema
```sql
ring_groups:
  - id
  - organization_id
  - name (unique per org)
  - description (nullable)
  - strategy (enum)
  - timeout (integer)
  - fallback_action (enum)
  - fallback_extension_id (nullable)
  - status (enum)
  - created_at
  - updated_at

ring_group_members:
  - id
  - ring_group_id
  - extension_id
  - priority (integer)
  - created_at
  - updated_at
```

### Relations
- Ring Group belongs to Organization
- Ring Group has many Ring Group Members
- Ring Group Member belongs to Extension
- Ring Group has optional Fallback Extension

---

## 18. Testing Requirements

### Unit Tests
- Form validation logic
- Priority calculation algorithms
- Circular reference detection
- Extension availability checks

### Integration Tests
- Create ring group with members
- Update ring group strategy
- Delete ring group with DID assignments
- Member reordering

### E2E Tests
- Complete create flow
- Edit and save changes
- Delete with confirmation
- Search and filter combinations

---

## 19. Documentation Needs

### User Documentation
- What is a ring group?
- When to use each strategy
- How to set up a ring group
- Troubleshooting common issues

### Admin Documentation
- Permission requirements
- Best practices for large groups
- Performance optimization
- Monitoring ring group health

---

## End of Specification

This specification provides a complete blueprint for implementing the Ring Groups management feature. All UI/UX details, workflows, validations, and edge cases are documented.

**Ready for implementation upon approval.**
