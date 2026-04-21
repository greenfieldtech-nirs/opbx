# Platform Management

## Overview
Cross-tenant administrative interface for platform managers. Provides organization management, user management, and audit logging across all tenants.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Platform/PlatformDashboardController.php` | Cross-tenant dashboard (162 lines) |
| `app/Http/Controllers/Platform/PlatformOrganizationController.php` | Org management (315 lines) |
| `app/Http/Controllers/Platform/PlatformUserController.php` | User management (363 lines) |
| `app/Http/Controllers/Platform/PlatformAuditLogController.php` | Audit log viewer (61 lines) |
| `app/Http/Middleware/EnsurePlatformManager.php` | Auth gate (36 lines) |
| `app/Services/PlatformAuditService.php` | Audit logging (53 lines) |
| `app/Models/PlatformAuditLog.php` | Audit log model |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/Platform/PlatformDashboard.tsx` | Dashboard |
| `frontend/src/pages/Platform/PlatformOrganizations.tsx` | Org listing |
| `frontend/src/pages/Platform/PlatformOrganizationDetail.tsx` | Org detail |
| `frontend/src/pages/Platform/PlatformUsers.tsx` | User listing |
| `frontend/src/pages/Platform/PlatformAuditLog.tsx` | Audit viewer |
| `frontend/src/components/platform/PlatformLayout.tsx` | Layout |
| `frontend/src/components/platform/PlatformManagerRoute.tsx` | Route guard |
| `frontend/src/services/platformApi.ts` | API calls |
| `frontend/src/hooks/platform/index.ts` | Platform hooks |

## API Routes (routes/platform.php)
All require `auth:sanctum` + `platform.manager` middleware.
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/platform/dashboard` | Platform-wide stats |
| GET | `/v1/platform/organizations` | List all orgs |
| GET/PUT | `/v1/platform/organizations/{org}` | Show/update org |
| PATCH | `/v1/platform/organizations/{org}/status` | Status transitions |
| GET | `/v1/platform/users` | List all users cross-tenant |
| GET | `/v1/platform/organizations/{org}/users` | Org-specific users |
| POST | `/v1/platform/organizations/{org}/users` | Create user in org |
| GET/PUT/DELETE | `/v1/platform/users/{user}` | User CRUD |
| PATCH | `/v1/platform/users/{user}/platform-manager` | Grant/revoke PM |
| PATCH | `/v1/platform/users/{user}/password` | Change password |
| GET | `/v1/platform/audit-logs` | View audit logs |

## Organization Status Transitions
```
active <-> suspended -> deleted (hard delete)
```
- Active -> Suspended: org users can't login
- Suspended -> Active: restores access
- Any -> Deleted: **cascading hard delete** in DB transaction (tokens -> extensions -> ring_groups -> business_hours -> DIDs -> call_logs -> users -> org.forceDelete)

## Guards
- Cannot delete last owner of an organization
- Cannot delete self
- Cannot revoke last platform manager
- Revoking PM revokes all tokens (forces re-auth)
- Sensitive settings masked in show response (`****XXXX`)

## Audit Logging
Every mutation logged via `PlatformAuditService::log()` with: who (PM user), what (action, entity), where (target org), before/after state, IP, user agent.

## Related Modules
- [Multi-Tenancy](multi-tenancy.md) - Platform managers bypass OrganizationScope
- [Authentication](authentication-authorization.md) - Platform manager abilities
