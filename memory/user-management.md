# User Management

## Overview
CRUD operations for users within an organization. Role hierarchy: Owner > PBX Admin > PBX User > Reporter.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/UsersController.php` | User CRUD (extends AbstractApiCrudController) |
| `app/Models/User.php` | User model |
| `app/Enums/UserRole.php` | OWNER, PBX_ADMIN, PBX_USER, REPORTER |
| `app/Enums/UserStatus.php` | ACTIVE, INACTIVE |
| `app/Policies/UserPolicy.php` | Authorization rules |
| `app/Http/Requests/User/` | Form request validators |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/UsersComplete.tsx` | User management page |
| `frontend/src/components/Users/UserForm.tsx` | User create/edit form |
| `frontend/src/components/Users/UserTable.tsx` | User data table |
| `frontend/src/components/Users/UserFilters.tsx` | Search/filter controls |
| `frontend/src/hooks/useUsers.ts` | User queries |
| `frontend/src/hooks/useUserMutations.ts` | User CRUD mutations |
| `frontend/src/hooks/useUserFilters.ts` | Filter state management |

## Database: `users` Table
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| name | string | |
| email | string | Unique globally |
| password | string | Hashed (bcrypt) |
| role | enum | owner, pbx_admin, pbx_user, reporter |
| status | enum | active, inactive |
| is_platform_manager | boolean | Cross-tenant admin flag |
| phone, street_address, city, state_province, postal_code, country | strings | Optional profile fields |
| email_verified_at, remember_token | | Standard Laravel |

## Authorization Matrix (UserPolicy)
| Action | Owner | PBX Admin | PBX User | Reporter |
|--------|-------|-----------|----------|----------|
| viewAny | Yes | Yes | No | No |
| view | All | All | Self only | No |
| create | Yes | Yes | No | No |
| update | All | All | Self only | No |
| updateRole | Yes (not self) | No | No | No |
| delete | Yes (not self) | PBX_USER/REPORTER only | No | No |
| updatePassword | Others only | PBX_USER/REPORTER only | No | No |

## Key Business Logic
- `User::canManageUser(User $target)` (`User.php:217`): Cannot manage self; must be same org; Owner manages all; PBX Admin manages PBX_USER/REPORTER only
- Password changes via profile revoke all tokens (forces re-login)
- UserRole enum has capability methods: `canManageOrganization()`, `canManageUsers()`, `canManageConfiguration()`, `canViewReports()`

## Related Modules
- [Authentication](authentication-authorization.md) - Login/registration
- [Profile Management](profile-management.md) - Self-service updates
- [Extensions](extensions.md) - User-extension association
