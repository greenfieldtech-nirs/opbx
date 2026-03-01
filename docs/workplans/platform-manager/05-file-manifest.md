## 5. File Manifest

### New Files

| File Path | Phase | Item ID | Description |
|---|---|---|---|
| `database/migrations/YYYY_MM_DD_HHMMSS_add_is_platform_manager_to_users_table.php` | 1 | PM-1.1.1 | Migration: add boolean column to users |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_platform_audit_logs_table.php` | 1 | PM-1.1.2 | Migration: create audit log table |
| `app/Models/PlatformAuditLog.php` | 1 | PM-1.2.2 | Audit log Eloquent model |
| `app/Services/PlatformAuditService.php` | 1 | PM-1.3.1 | Service for writing audit log entries |
| `app/Http/Middleware/EnsurePlatformManager.php` | 1 | PM-1.5.1 | Middleware: validates platform manager flag |
| `routes/platform.php` | 1 | PM-1.7.1 | Platform API route definitions |
| `app/Http/Controllers/Platform/PlatformDashboardController.php` | 2 | PM-2.1.1 | Dashboard stats endpoint |
| `app/Http/Controllers/Platform/PlatformOrganizationController.php` | 2 | PM-2.2.1 | Organization CRUD endpoints |
| `app/Http/Controllers/Platform/PlatformUserController.php` | 2 | PM-2.3.1 | User management endpoints |
| `app/Http/Controllers/Platform/PlatformAuditLogController.php` | 2 | PM-2.4.1 | Audit log listing endpoint |
| `app/Http/Requests/Platform/UpdateOrganizationStatusRequest.php` | 2 | PM-2.2.5 | Form request: status change validation |
| `app/Http/Requests/Platform/UpdateOrganizationSettingsRequest.php` | 2 | PM-2.2.6 | Form request: settings update validation |
| `app/Http/Requests/Platform/PlatformCreateUserRequest.php` | 2 | PM-2.3.8 | Form request: user creation validation |
| `app/Http/Requests/Platform/PlatformUpdateUserRequest.php` | 2 | PM-2.3.9 | Form request: user update validation |
| `app/Http/Requests/Platform/PlatformSetManagerRequest.php` | 2 | PM-2.3.10 | Form request: set PM flag validation |
| `app/Console/Commands/CreatePlatformManager.php` | 3 | PM-3.1.1 | Interactive create command |
| `app/Console/Commands/SetPlatformManager.php` | 3 | PM-3.1.2 | Set flag command |
| `app/Console/Commands/RevokePlatformManager.php` | 3 | PM-3.1.3 | Revoke flag command |
| `frontend/src/types/platform.ts` | 4 | PM-4.1.2 | TypeScript types for platform entities |
| `frontend/src/services/platformApi.ts` | 4 | PM-4.2.1 | API client for platform endpoints |
| `frontend/src/components/platform/PlatformManagerRoute.tsx` | 4 | PM-4.3.1 | Route guard component |
| `frontend/src/components/platform/PlatformLayout.tsx` | 4 | PM-4.3.2 | Layout wrapper component |
| `frontend/src/components/platform/OrganizationStatusBadge.tsx` | 4 | PM-4.3.3 | Status badge component |
| `frontend/src/components/platform/AuditLogEntry.tsx` | 4 | PM-4.3.4 | Audit log entry display component |
| `frontend/src/hooks/platform/usePlatformDashboard.ts` | 4 | PM-4.4.1 | TanStack Query hook: dashboard |
| `frontend/src/hooks/platform/usePlatformOrganizations.ts` | 4 | PM-4.4.1 | TanStack Query hook: org list |
| `frontend/src/hooks/platform/usePlatformOrganization.ts` | 4 | PM-4.4.1 | TanStack Query hook: org detail |
| `frontend/src/hooks/platform/usePlatformUsers.ts` | 4 | PM-4.4.1 | TanStack Query hook: user list |
| `frontend/src/hooks/platform/usePlatformUser.ts` | 4 | PM-4.4.1 | TanStack Query hook: user detail |
| `frontend/src/hooks/platform/usePlatformAuditLogs.ts` | 4 | PM-4.4.1 | TanStack Query hook: audit logs |
| `frontend/src/pages/platform/PlatformDashboard.tsx` | 5 | PM-5.1.1 | Dashboard page |
| `frontend/src/pages/platform/PlatformOrganizations.tsx` | 5 | PM-5.2.1 | Organizations list page |
| `frontend/src/pages/platform/PlatformOrganizationDetail.tsx` | 5 | PM-5.2.2 | Organization detail page |
| `frontend/src/pages/platform/PlatformUsers.tsx` | 5 | PM-5.3.1 | Users list page |
| `frontend/src/pages/platform/PlatformUserDetail.tsx` | 5 | PM-5.3.2 | User detail page |
| `frontend/src/pages/platform/PlatformAuditLog.tsx` | 5 | PM-5.4.1 | Audit log page |
| `tests/Unit/Scopes/OrganizationScopeBypassTest.php` | 7 | PM-7.1.1 | Unit tests: scope bypass |
| `tests/Unit/Middleware/EnsurePlatformManagerTest.php` | 7 | PM-7.1.2 | Unit tests: middleware |
| `tests/Unit/Models/UserPlatformManagerTest.php` | 7 | PM-7.1.3 | Unit tests: user model |
| `tests/Feature/Platform/PlatformDashboardTest.php` | 7 | PM-7.2.1 | Feature tests: dashboard |
| `tests/Feature/Platform/PlatformOrganizationTest.php` | 7 | PM-7.2.2 | Feature tests: organizations |
| `tests/Feature/Platform/PlatformUserTest.php` | 7 | PM-7.2.3 | Feature tests: users |
| `tests/Feature/Platform/PlatformAuditLogTest.php` | 7 | PM-7.2.4 | Feature tests: audit logs |
| `tests/Feature/Commands/PlatformManagerCommandsTest.php` | 7 | PM-7.3.1 | Feature tests: artisan commands |

### Modified Files

| File Path | Phase | Item ID | Description |
|---|---|---|---|
| `app/Models/User.php` | 1 | PM-1.2.1 | Add `$casts`, method, relationship |
| `app/Scopes/OrganizationScope.php` | 1 | PM-1.4.1 | Add bypass mechanism |
| `bootstrap/app.php` | 1 | PM-1.5.2 | Register middleware alias |
| `app/Http/Controllers/AuthController.php` | 1 | PM-1.6.1 | Add platform token abilities |
| `routes/api.php` | 1 | PM-1.7.1 | Include platform routes file |
| `app/Providers/AppServiceProvider.php` | 1 | PM-1.8.1 | Route model binding override |
| `frontend/src/types/auth.ts` | 4 | PM-4.1.1 | Add `is_platform_manager` to User type |
| `frontend/src/router.tsx` | 4 | PM-4.5.1 | Add platform routes |
| `frontend/src/components/Sidebar.tsx` | 4 | PM-4.5.2 | Add platform nav section |

---

