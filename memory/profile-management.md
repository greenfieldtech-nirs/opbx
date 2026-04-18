# Profile Management

## Overview
Self-service profile management for authenticated users. Includes personal info, organization settings (owner-only), and password changes.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/ProfileController.php` | Profile CRUD |
| `app/Http/Requests/Profile/` | Profile form request validators |
| `frontend/src/pages/Profile.tsx` | Profile page |
| `frontend/src/services/profile.service.ts` | Profile API calls |

## API Routes
| Method | URI | Controller | Notes |
|--------|-----|-----------|-------|
| GET | `/v1/profile` | ProfileController@show | Returns user + org details |
| PUT | `/v1/profile` | ProfileController@update | Updates name, email, phone, address |
| PUT | `/v1/profile/organization` | ProfileController@updateOrganization | Owner-only: name, timezone |
| PUT | `/v1/profile/password` | ProfileController@updatePassword | Revokes ALL tokens after change |

## Key Business Logic
- Profile updates are partial (only sent fields are updated)
- Role changes are audit-logged at WARNING level
- Password change revokes all Sanctum tokens (forces re-auth on all devices)
- Organization updates require Owner role

## Related Modules
- [Authentication](authentication-authorization.md) - Token management
- [User Management](user-management.md) - Admin-level user updates
