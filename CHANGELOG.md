# Changelog

All notable changes to the OPBX project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added - 2025-12-26

#### Ring Groups Backend Integration & API Implementation

- **Laravel Backend Implementation**
  - Database Migrations (`database/migrations/*_create_ring_groups_table.php`, `*_create_ring_group_members_table.php`)
    - ring_groups table with organization_id, name, description, strategy, timeout, ring_turns, fallback_action, fallback_extension_id, status
    - ring_group_members pivot table with ring_group_id, extension_id, priority
    - Indexes and foreign key constraints with cascade delete
    - Unique constraint on organization_id + name

  - Models (`app/Models/RingGroup.php`, `app/Models/RingGroupMember.php`)
    - Full Eloquent relationships (organization, members, fallbackExtension)
    - Enum casts for strategy, fallback_action, status
    - Query scopes: forOrganization, withStrategy, withStatus, search, active
    - Tenant scoping via OrganizationScope trait

  - Enums (`app/Enums/`)
    - RingGroupStrategy: SIMULTANEOUS, ROUND_ROBIN, SEQUENTIAL
    - RingGroupStatus: ACTIVE, INACTIVE
    - RingGroupFallbackAction: VOICEMAIL, EXTENSION, HANGUP, REPEAT

  - Authorization (`app/Policies/RingGroupPolicy.php`)
    - viewAny: All authenticated users
    - view: All users (same organization)
    - create/update/delete: Owner and PBX Admin only

  - Form Request Validators (`app/Http/Requests/RingGroup/`)
    - StoreRingGroupRequest with custom validation rules
    - UpdateRingGroupRequest with unique name validation
    - Custom validation for extension type (must be 'user') and status (must be 'active')
    - Organization ownership validation
    - Fallback extension validation
    - Member duplicate prevention

  - API Controller (`app/Http/Controllers/Api/RingGroupController.php`)
    - Full REST CRUD operations
    - index(): Paginated list with filters (strategy, status, search) and sorting
    - store(): Create with transaction safety
    - show(): Single ring group with relationships
    - update(): Update with transaction safety (replace members strategy)
    - destroy(): Delete with transaction safety
    - Structured logging with request_id correlation
    - Proper HTTP status codes (200, 201, 204, 403, 404, 500)

  - API Routes (`routes/api.php`)
    - RESTful resource routes under /api/v1/ring-groups
    - Protected by auth:sanctum and tenant.scope middleware

- **Frontend Integration**
  - Updated API Types (`frontend/src/types/api.types.ts`)
    - RingGroupMember interface with extension details
    - Updated RingGroup interface to match backend
    - CreateRingGroupRequest and UpdateRingGroupRequest types
    - RingGroupFallbackAction and RingGroupStatus types

  - Ring Groups Service (`frontend/src/services/ringGroups.service.ts`)
    - Complete service layer with all CRUD methods
    - Filter support (search, strategy, status, sort_by, sort_direction)
    - Pagination support

  - Ring Groups Page Integration (`frontend/src/pages/RingGroups.tsx`)
    - Replaced mock data with React Query hooks
    - useQuery for fetching ring groups with filters and pagination
    - useQuery for fetching available extensions (type: user, status: active)
    - useMutation for create, update, delete operations
    - Query invalidation after mutations
    - Loading and error states
    - Toast notifications for success/error feedback
    - useAuth hook for current user permissions
    - Data transformation between frontend and backend formats

#### Ring Groups Feature (UI/UX Implementation)
- **Ring Groups Management Page** (`frontend/src/pages/RingGroups.tsx`)
  - Full CRUD operations (Create, Read, Update, Delete) for ring groups
  - Comprehensive table view with 8 sample ring groups
  - Search functionality by name and description
  - Filter by ring strategy (simultaneous, round_robin, sequential)
  - Filter by status (active, inactive)
  - Column sorting (name, strategy, members count, status)
  - Create/Edit dialogs with comprehensive form validation
  - Delete confirmation dialog
  - Detail sheet (side panel) for viewing ring group details
  - Role-based permissions (owner/pbx_admin only for management)

- **Ring Group Features**
  - Three ring strategies:
    - **Simultaneous (Ring All)**: All members ring at the same time
    - **Round Robin**: Calls distributed evenly across members in rotation
    - **Sequential**: Ring members one at a time based on priority order
  - Member management:
    - Add/remove extension members
    - Reorder members with up/down arrow buttons (no drag-drop library)
    - Priority ordering for sequential strategy (1-100)
    - Prevent duplicate extension assignments
  - Timeout configuration (5-300 seconds)
  - Four fallback actions:
    - Voicemail
    - Forward to Extension (with extension selector)
    - Hangup
    - Repeat (try again)
  - Status toggle (active/inactive)

- **Mock Data** (`frontend/src/mock/ringGroups.ts`)
  - 8 sample ring groups with varied configurations
  - 10 mock PBX User extensions (type: user, status: active)
  - Type definitions: RingGroupStrategy, RingGroupStatus, FallbackAction, RingGroupMember, RingGroup
  - Helper functions: getNextRingGroupId, getStrategyDisplayName, getStrategyDescription, getFallbackDisplayText

- **Ring Groups Specification** (`RING_GROUPS_SPECIFICATION.md`)
  - Complete 17-section specification document
  - Data model and field definitions
  - Ring strategy descriptions and behaviors
  - Member management rules and constraints
  - Timeout and fallback action specifications
  - UI/UX mockups and workflows
  - Role-based permissions matrix
  - Validation rules
  - API endpoint specifications
  - Database schema design
  - Edge case handling
  - Future enhancements roadmap

- **UI Components**
  - Alert component (`frontend/src/components/ui/alert.tsx`)
    - Alert, AlertTitle, AlertDescription exports
    - Variant support (default, destructive)
    - Used for displaying info banner about extension type constraints

#### Constraints & Validation
- **Extension Type Constraint**: Only PBX User extensions (type: "user", status: "active") can be added to ring groups
  - Info banner displayed in create/edit dialogs
  - API endpoint filters: `GET /api/v1/extensions?type=user&status=active`
  - Frontend excludes already-assigned extensions from selection
- **Validation Rules**:
  - Name: 2-100 characters, required
  - Members: 1-50 extensions, at least 1 required
  - Timeout: 5-300 seconds, required
  - Fallback extension: required when fallback action is "extension"
  - Prevent duplicate extension assignments within a ring group

#### Navigation & Routing
- Ring Groups route already configured at `/ring-groups` in router.tsx
- Sidebar navigation item already present with UserPlus icon

### Fixed - 2025-12-26
- TypeScript error in `getNextRingGroupId` function - added null check for array split operation
- Missing Alert component - created shadcn/ui compatible Alert component

### Technical Details

#### Files Changed
- `frontend/src/pages/RingGroups.tsx` - Complete rewrite with full functionality (1,128 lines)
- `frontend/src/mock/ringGroups.ts` - New file (245 lines)
- `frontend/src/components/ui/alert.tsx` - New file (62 lines)
- `RING_GROUPS_SPECIFICATION.md` - New file (comprehensive documentation)

#### Dependencies
- No new package dependencies required
- Used up/down arrow buttons for reordering (avoided drag-drop libraries)
- All UI components from existing shadcn/ui library

#### Implementation Notes
- Uses mock data only (no API integration in this commit)
- All operations are in-memory and reset on page refresh
- Ready for backend API integration
- TypeScript type-safe throughout
- Follows existing codebase patterns and conventions

---

## [0.1.0] - 2025-12-26

### Added
- Initial project commit with base OPBX structure
- Laravel backend framework setup
- React frontend with TypeScript
- Basic routing and authentication
- User management
- Extensions management
- Conference Rooms feature
- DIDs management
- Docker containerization
- Multi-tenant architecture

---

## Notes

### Ring Groups - Next Steps
When ready to integrate with backend API:
1. Create Laravel API endpoints as specified in RING_GROUPS_SPECIFICATION.md
2. Implement RingGroup model with relationships
3. Create database migration for ring_groups and ring_group_members tables
4. Replace mock data with API service calls
5. Add React Query hooks for data fetching and mutations
6. Implement real-time updates for ring group changes
7. Add comprehensive backend validation matching frontend rules
8. Write unit tests for ring group business logic
9. Add integration tests for ring group API endpoints

### Conventions
- **[Unreleased]**: Features in development, not yet in a tagged release
- **[Version]**: Tagged releases with date
- **Categories**: Added, Changed, Deprecated, Removed, Fixed, Security
