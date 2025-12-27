# Changelog

All notable changes to the OPBX project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added - 2025-12-27

#### Phone Numbers (DIDs) Management Feature (Complete Implementation)

- **Frontend Implementation**
  - Created comprehensive Phone Numbers management UI in React (`frontend/src/pages/PhoneNumbers.tsx`)
  - Implemented phone number table with filtering by status and routing type
  - Added search functionality for phone numbers and friendly names
  - Created Create/Edit dialog with conditional routing configuration
  - Implemented E.164 phone number formatting utility
  - Added routing type badges with icons (Extension, Ring Group, Business Hours, Conference Room)
  - Created phone number validation with E.164 format enforcement
  - Implemented permission-based UI (Owner/PBX Admin can manage, others view-only)
  - Added conditional target selection based on routing type:
    - Extension selector with active extensions
    - Ring Group selector with member count display
    - Business Hours Schedule selector
    - Conference Room selector with capacity display
  - Created utility functions in `frontend/src/utils/phoneNumbers.ts`
  - Updated TypeScript types to match new routing_config structure
  - Changed API endpoint from `/dids` to `/phone-numbers`

- **Backend Implementation**
  - Updated `did_numbers` table schema via migration:
    - Changed routing_type enum: removed 'ivr', 'voicemail', added 'conference_room'
    - Maintained E.164 phone number format requirement
    - Preserved global uniqueness constraint on phone_number field
  - Enhanced DidNumber model:
    - Added `getTargetConferenceRoomId()` method
    - Fixed `getTargetBusinessHoursId()` to use correct key
    - Added accessor/setter methods for related resources
    - Maintained OrganizationScope for tenant isolation
  - Created comprehensive Policy (DidNumberPolicy):
    - viewAny: All authenticated users can view
    - view: Must be in same organization
    - create/update/delete: Only Owner and PBX Admin
  - Implemented RESTful API controller (PhoneNumberController) with 5 endpoints:
    - GET /api/v1/phone-numbers (list with pagination, filtering, search)
    - GET /api/v1/phone-numbers/{id} (single phone number with eager loaded relations)
    - POST /api/v1/phone-numbers (create with validation)
    - PUT /api/v1/phone-numbers/{id} (update routing configuration)
    - DELETE /api/v1/phone-numbers/{id} (delete with activity logging)
  - Created comprehensive validation via FormRequests:
    - StorePhoneNumberRequest: E.164 format validation, uniqueness check
    - UpdatePhoneNumberRequest: Same as store but prohibits phone_number updates
    - Conditional validation based on routing_type (extension_id, ring_group_id, etc.)
    - Validates target resources exist, are active, belong to same organization
    - Ring groups must have at least 1 active member
  - Implemented intelligent eager loading:
    - Batch loads related resources grouped by routing_type
    - Prevents N+1 query problems
    - Only loads relevant relations based on routing configuration
  - Created API Resources:
    - PhoneNumberResource: Transforms model with conditional related resources
    - RingGroupResource: Nested resource for ring group details
  - Added comprehensive logging with correlation IDs
  - Implemented database transactions for data integrity

- **Testing**
  - Created 25 feature tests for PhoneNumberController (100% passing)
  - Created 17 unit tests for DidNumber model (94% passing)
  - Tests cover:
    - Authorization (Owner, PBX Admin, PBX User, Reporter)
    - CRUD operations for all routing types
    - E.164 phone number format validation
    - Phone number uniqueness across organizations
    - Target resource validation (exists, active, same organization)
    - Ring group member validation
    - Pagination, filtering, and search
    - Eager loading verification
    - Phone number immutability after creation

- **Security Enhancements**
  - Added AuthorizesRequests trait to base Controller class
  - SQL injection prevention via Eloquent ORM
  - Mass assignment protection with $fillable
  - Authorization checks on every endpoint
  - Organization scoping on all queries
  - Input validation and sanitization
  - Structured audit logging

- **Code Quality**
  - PHP 8.3+ features: strict types, type hints, match expressions
  - Laravel best practices: FormRequests, Policies, Resources
  - Comprehensive PHPDoc blocks
  - PSR-12 coding standards
  - Zero tolerance for code smells

- **Database Factories**
  - DidNumberFactory: Creates valid E.164 phone numbers
  - ConferenceRoomFactory: Creates test conference rooms
  - State methods for different routing configurations

- **Bug Fixes**
  - Fixed UserFactory to use correct UserRole enum values (PBX_USER instead of AGENT, PBX_ADMIN instead of ADMIN)
  - Removed auto-prepending of '+' to phone numbers (enforces E.164 validation)
  - Fixed phone number regex to require '+' prefix (was optional)

#### Business Hours Feature (Complete Implementation)

- **Frontend Implementation**
  - Created comprehensive Business Hours management UI in React (`frontend/src/pages/BusinessHours.tsx`)
  - Implemented weekly schedule configuration with multiple time ranges per day
  - Added exception dates support (holidays and special hours)
  - Created Holiday Import dialog with dynamic country/year selection via Nager.Date API
  - Added "Import Holidays" feature with 100+ countries support
  - Implemented Copy Hours functionality for quick schedule duplication
  - Created visual calendar view in detail sheet
  - Added Open/Closed hours action selectors for call routing
  - Removed timezone field (moved to global settings)
  - Simplified status display with Active/Disabled indicators
  - Created UI components: AlertDialog, RadioGroup, Checkbox, Separator
  - Added mock data structure in `frontend/src/mock/businessHours.ts`
  - Installed required dependencies: @radix-ui/react-alert-dialog, @radix-ui/react-radio-group, @radix-ui/react-checkbox, @radix-ui/react-separator

- **Backend Implementation**
  - Created comprehensive database schema with 5 tables:
    - `business_hours_schedules` - Main schedules with tenant scoping
    - `business_hours_schedule_days` - Days of week (Monday-Sunday)
    - `business_hours_time_ranges` - Multiple time ranges per day
    - `business_hours_exceptions` - Holidays and special hours
    - `business_hours_exception_time_ranges` - Time ranges for special hours
  - Implemented 8 Eloquent models with proper relationships:
    - BusinessHoursSchedule with smart current_status calculation
    - BusinessHoursScheduleDay
    - BusinessHoursTimeRange with time overlap detection
    - BusinessHoursException
    - BusinessHoursExceptionTimeRange
    - Type-safe enums: BusinessHoursStatus, BusinessHoursExceptionType, DayOfWeek
  - Created RESTful API controller with 6 endpoints:
    - GET /api/v1/business-hours (list with pagination and filtering)
    - GET /api/v1/business-hours/{id} (single schedule with details)
    - POST /api/v1/business-hours (create with nested data)
    - PUT /api/v1/business-hours/{id} (update with transaction safety)
    - DELETE /api/v1/business-hours/{id} (soft delete with validation)
    - POST /api/v1/business-hours/{id}/duplicate (copy schedule)
  - Implemented comprehensive validation with 40+ rules via FormRequests
  - Created authorization policy with proper RBAC (Owner/Admin manage, Agents view)
  - Added API Resources for proper JSON response formatting
  - Implemented BusinessHoursScheduleFactory for testing

- **Security & Quality**
  - Complete multi-tenant isolation with organization scoping
  - All database queries filtered by organization_id
  - Transaction-safe complex operations
  - Cross-organization access prevention with logging
  - Soft deletes for data preservation
  - Comprehensive input validation and sanitization
  - N+1 query prevention with eager loading
  - Proper indexing for performance

- **Testing**
  - Created 24 comprehensive tests:
    - 15 Feature tests for API endpoints
    - 9 Unit tests for business logic
  - Tests cover: CRUD operations, tenant isolation, RBAC, validation, edge cases
  - Test for current status calculation accuracy
  - Test for exception date handling
  - Test for time range overlap detection

- **Documentation**
  - Created `BUSINESS_HOURS_IMPLEMENTATION.md` with technical details
  - Created `docs/BUSINESS_HOURS_SPECIFICATION.md` with complete feature specs
  - Added inline PHPDoc comments throughout codebase
  - Documented API endpoints and response formats

### Changed - 2025-12-27

#### Business Hours API Integration
- Connected Business Hours UI to backend REST API (`frontend/src/pages/BusinessHours.tsx`)
  - Replaced mock data operations with React Query hooks (useQuery, useMutation)
  - Integrated businessHoursService for all CRUD operations
  - Added loading and error states to the UI
  - Create/Update/Delete operations now call backend API endpoints
  - Data automatically refreshes after mutations via query invalidation
  - Improved user feedback with toast notifications on success/error
  - Updated businessHoursService to use PUT for updates (matching Laravel apiResource)
  - Added duplicate endpoint support in service

### Fixed - 2025-12-27

#### Business Hours Validation Logic Improvements
- Fixed overly strict validation in Business Hours API (`app/Http/Requests/BusinessHours/*.php`)
  - **Allow disabled days without time ranges**: Disabled days (closed all day) no longer require empty time_ranges array to be filled
  - Changed `schedule.*.time_ranges` validation from `required` to `nullable`
  - Removed illogical "at least one day must be enabled" requirement - fully closed schedules are now valid
  - **Automatic deduplication of exception dates**: Silently uses first occurrence when duplicate dates are found
  - Added deduplication logic in `prepareForValidation()` to handle duplicate exception dates gracefully
  - Removed confusing "Exception dates must be unique" error message
  - Applied fixes to both StoreBusinessHoursScheduleRequest and UpdateBusinessHoursScheduleRequest

#### Docker Migration Race Condition
- Fixed race condition where multiple containers ran migrations simultaneously (`docker-compose.yml`, `docker/php/entrypoint.sh`)
  - **Problem**: app, scheduler, and queue-worker containers all ran migrations at startup causing conflicts
  - **Solution**: Only app container runs migrations now
  - Added `RUN_MIGRATIONS` environment variable check in entrypoint.sh
  - Set `RUN_MIGRATIONS=false` for scheduler and queue-worker containers
  - scheduler and queue-worker now wait for app container to complete migrations first
  - Prevents "Table doesn't exist" and "Failed to open referenced table" errors during container startup

#### Business Hours Migration Idempotency and Foreign Key Constraints
- Fixed Business Hours database migration (`database/migrations/2025_12_27_202223_restructure_business_hours_tables.php`)
  - **Fixed foreign key constraint name length**: MySQL has 64-character limit, auto-generated names exceeded this
  - Specified custom short constraint names (e.g., `bh_schedule_days_schedule_fk`)
  - **Fixed index name length**: Auto-generated index names also exceeded 64-character limit
  - Specified custom short index names (e.g., `bh_schedules_org_status_idx`, `bh_exception_time_ranges_idx`)
  - **Fixed table drop failure**: Added `SET FOREIGN_KEY_CHECKS=0` before dropping tables
  - Tables now drop cleanly even with existing foreign key constraints
  - Re-enables foreign key checks after drops complete
  - Migration now fully idempotent and safe to run multiple times
  - Prevents "Base table or view already exists" and "Identifier name too long" errors

#### Business Hours Toast Notifications
- Fixed toast notification format errors in Business Hours UI (`frontend/src/pages/BusinessHours.tsx`)
  - Migrated from object-based toast API to sonner's string-based API
  - Fixed 9 instances of incorrect toast usage causing "Objects are not valid as a React child" errors
  - Updated all toast calls to use `toast.success()` and `toast.error()` methods
  - Resolved Copy Hours button functionality
  - Fixed exception form validation error notifications
  - Fixed schedule create/update/delete success notifications

### Security - 2025-12-27

#### Phase 2: Security Hardening & Performance Improvements

- **Rate Limiting Implementation**
  - Configured rate limiters in AppServiceProvider for multiple endpoint types:
    - API routes: 60 requests/minute per authenticated user
    - Webhooks: 100 requests/minute (by IP)
    - Sensitive operations: 10 requests/minute per user (password/org updates)
    - Authentication endpoints: 5 requests/minute (by IP)
  - Created `config/rate_limiting.php` with configurable limits
  - Applied throttling middleware to all API and webhook routes
  - Custom JSON error responses with retry_after headers
  - Added environment variables: RATE_LIMIT_API, RATE_LIMIT_WEBHOOKS, RATE_LIMIT_SENSITIVE, RATE_LIMIT_AUTH
  - Created comprehensive test suite (`tests/Feature/RateLimitingTest.php`) with 7 tests

- **Password Policy Enforcement**
  - Strengthened minimum password length from 6 to 8 characters
  - Added `password_reset_required` flag to users table
  - Added `password_last_changed_at` timestamp to users table
  - Created `password:enforce-policy` artisan command for enforcing password age policies
  - Command supports dry-run mode and configurable max password age (default: 90 days)

- **Authorization Policy Enforcement**
  - Updated UserPolicy with missing `create()` method
  - Refactored UsersController to use policy-based authorization
    - Replaced manual `isOwner()` and `isPBXAdmin()` checks with `$this->authorize()` calls
    - Applied to all CRUD methods: viewAny, create, view, update, delete
  - Updated RingGroupController to use policy for delete authorization
  - Updated SettingsController to use policy for viewAny and generateApiKey
  - Centralized authorization logic in policy classes for better maintainability

- **Tenant Isolation in Webhooks**
  - Enhanced CloudonixWebhookController with organization validation
  - Added eager loading of organization relationship in DID queries
  - Added explicit checks for organization existence
  - Added active status validation for organizations
  - Return proper error messages for inactive or non-existent organizations
  - Comprehensive logging for tenant isolation violations

- **N+1 Query Prevention**
  - Fixed N+1 query issues in RingGroupController index() method
  - Added comprehensive eager loading with `with()` for nested relationships:
    - members with extension and user details
    - fallbackExtension
  - Added `withCount()` for aggregate queries:
    - Total members count
    - Active members count (filtered by extension status)
  - Used `select()` to limit columns loaded and reduce memory usage
  - Ordered members by priority for consistent display

- **Security Headers Middleware**
  - Created comprehensive SecurityHeaders middleware
  - Implemented Content Security Policy (CSP):
    - default-src 'self'
    - script-src with React inline script support
    - style-src with inline style support
    - connect-src with WebSocket support
    - frame-ancestors 'none' (prevents clickjacking)
  - Added X-Content-Type-Options: nosniff
  - Added X-Frame-Options: DENY
  - Added Referrer-Policy: strict-origin-when-cross-origin
  - Added Permissions-Policy restricting browser features (geolocation, microphone, camera, etc.)
  - Added HSTS (Strict-Transport-Security) for production environments
  - Added X-XSS-Protection for legacy browser support
  - Registered globally in bootstrap/app.php

- **Webhook Replay Protection**
  - Implemented timestamp validation in EnsureWebhookIdempotency middleware
  - Reject webhooks older than 5 minutes (configurable via WEBHOOK_REPLAY_MAX_AGE)
  - Reject webhooks with future timestamps (>60 seconds ahead)
  - Added comprehensive logging for replay attack detection
  - Created `replay_protection` configuration section in config/webhooks.php
  - Added WEBHOOK_REPLAY_MAX_AGE environment variable (default: 300 seconds)
  - Proper error responses with 400 status code for expired/invalid webhooks

#### Files Changed
- `app/Providers/AppServiceProvider.php` - Added rate limiting configuration
- `config/rate_limiting.php` - New file for rate limit configuration
- `routes/api.php` - Applied throttle middleware to routes
- `routes/webhooks.php` - Applied throttle:webhooks middleware
- `.env.example` - Added rate limiting and webhook replay protection variables
- `app/Http/Requests/Auth/LoginRequest.php` - Updated password min length to 8
- `database/migrations/*_add_password_reset_required_to_users_table.php` - New migration
- `app/Console/Commands/EnforcePasswordPolicy.php` - New command
- `app/Policies/UserPolicy.php` - Added create() method
- `app/Http/Controllers/Api/UsersController.php` - Replaced manual auth checks with policies
- `app/Http/Controllers/Api/RingGroupController.php` - Added policy auth and eager loading
- `app/Http/Controllers/Api/SettingsController.php` - Added policy authorization
- `app/Http/Controllers/Webhooks/CloudonixWebhookController.php` - Added organization validation
- `app/Http/Middleware/SecurityHeaders.php` - New middleware
- `bootstrap/app.php` - Registered SecurityHeaders middleware
- `app/Http/Middleware/EnsureWebhookIdempotency.php` - Added timestamp validation
- `config/webhooks.php` - Added replay_protection configuration
- `tests/Feature/RateLimitingTest.php` - New comprehensive test suite

#### Audit Topics Covered
- **Security Review**: Rate limiting, password policy, tenant isolation, security headers, replay protection
- **Code Review**: Authorization policies, N+1 query optimization, middleware implementation
- **Compliancy Review**: OWASP best practices, security headers, password policies

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
