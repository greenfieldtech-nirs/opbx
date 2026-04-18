# Authentication & Authorization

## Overview
Dual-mode authentication (cookie-based SPA + Bearer token API) via Laravel Sanctum. Role-based access control with 4 roles. Platform manager flag for cross-tenant administration.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/AuthController.php` | Login/logout/refresh/me endpoints (506 lines) |
| `app/Http/Controllers/Api/RegisterController.php` | Organization + admin user registration |
| `app/Models/User.php` | User model with OrganizationScope |
| `app/Models/Organization.php` | Tenant model with SoftDeletes |
| `app/Enums/UserRole.php` | OWNER, PBX_ADMIN, PBX_USER, REPORTER |
| `app/Enums/UserStatus.php` | ACTIVE, INACTIVE |
| `app/Enums/OrganizationStatus.php` | ACTIVE, SUSPENDED, DELETED |
| `app/Policies/UserPolicy.php` | Role-based user management rules |
| `app/Http/Requests/Auth/LoginRequest.php` | Login validation |
| `app/Http/Requests/Auth/RegisterRequest.php` | Registration validation + reCAPTCHA |
| `config/sanctum.php` | Sanctum config (24h token expiry) |
| `config/auth.php` | Auth guards and providers |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/context/AuthContext.tsx` | Auth state (user, token, isAuthenticated) |
| `frontend/src/hooks/useAuth.ts` | Re-exports AuthContext |
| `frontend/src/services/auth.service.ts` | Login/register/logout/me API calls |
| `frontend/src/services/api.ts` | Axios with Bearer token interceptor |
| `frontend/src/pages/Login.tsx` | Login form with Zod validation |
| `frontend/src/pages/Register.tsx` | Two-step registration (org + admin) |
| `frontend/src/components/Auth/ProtectedRoute.tsx` | Redirects unauthenticated to /ui/login |
| `frontend/src/components/Auth/OwnerRoute.tsx` | Owner-only route guard |

## API Routes
| Method | URI | Controller | Middleware |
|--------|-----|-----------|-----------|
| POST | `/v1/auth/login` | AuthController@login | `throttle:auth` |
| POST | `/v1/auth/register` | RegisterController@register | `throttle:registration` |
| GET | `/v1/auth/register/validate` | RegisterController@validateRegistration | |
| POST | `/v1/auth/logout` | AuthController@logout | `auth:sanctum` |
| POST | `/v1/auth/refresh` | AuthController@refresh | `auth:sanctum` |
| GET | `/v1/auth/me` | AuthController@me | `auth:sanctum` |

## Auth Mode Detection (`AuthController.php:263`)
`shouldUseCookieAuth()` method:
1. `Authorization: Bearer` header present -> token mode
2. `X-Auth-Mode: cookie` header -> cookie mode
3. `X-Auth-Mode: token` header -> token mode
4. `X-Requested-With` header (AJAX) -> cookie mode
5. Default -> token mode

## Token Abilities (Role-Based)
| Role | Key Abilities |
|------|--------------|
| owner | Full wildcard on all resources (`extension:*`, `user:*`, `settings:*`, etc.) |
| pbx_admin | Full on extensions/ring-groups/DIDs/BH/conference/IVR; limited user (`user:read`, `user:update`) |
| pbx_user | Read on most; `extension:update:own` only |
| reporter | Read-only on extensions, users, ring-groups, DIDs, recordings, call-logs |

Platform managers get additional: `platform:read`, `platform:write`, `platform:manage-users`, `platform:manage-organizations`, `platform:audit-logs`

## Registration Flow (`RegisterController.php:40`)
1. Validates via RegisterRequest (org name, email, password, optional reCAPTCHA)
2. DB transaction: create Organization -> create User (role=OWNER) -> create Sanctum token
3. Returns 201 with user, organization, access_token

## Login Flow (`AuthController.php:120`)
1. Lookup user by email (bypasses org scope)
2. Verify password with Hash::check()
3. Check user.status === ACTIVE, org.isActive()
4. Detect auth mode (cookie vs token)
5. Cookie: session login | Token: delete old tokens, create new scoped token (24h)
6. Returns user payload

## Frontend Auth Flow
- Token stored in localStorage (`opbx_token`, `opbx_user`)
- Axios interceptor auto-attaches `Authorization: Bearer` header
- On 401 response: clears storage, redirects to /ui/login
- On mount: verifies token via /auth/me (skips on public pages)

## Password Requirements (Frontend)
Min 8 chars, uppercase, lowercase, number, special character

## Related Modules
- [Multi-Tenancy](multi-tenancy.md) - OrganizationScope enforcement
- [User Management](user-management.md) - User CRUD
- [Profile Management](profile-management.md) - Self-service profile
- [Platform Management](platform-management.md) - Cross-tenant admin
