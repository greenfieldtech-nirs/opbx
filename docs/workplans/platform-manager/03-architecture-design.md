## 3. Architecture Design

### 3.1 Database Changes

#### 3.1.1 Migration: Add `is_platform_manager` to `users` table

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_manager')
                ->default(false)
                ->after('role')
                ->index('idx_users_is_platform_manager');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_is_platform_manager');
            $table->dropColumn('is_platform_manager');
        });
    }
};
```

#### 3.1.2 Migration: Create `platform_audit_logs` table

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_manager_user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('target_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            $table->string('action', 100);
            $table->string('target_entity_type', 100)->nullable();
            $table->unsignedBigInteger('target_entity_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('platform_manager_user_id', 'idx_pal_manager_user');
            $table->index('target_organization_id', 'idx_pal_target_org');
            $table->index('action', 'idx_pal_action');
            $table->index('created_at', 'idx_pal_created_at');
            $table->index(
                ['target_entity_type', 'target_entity_id'],
                'idx_pal_target_entity'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
```

### 3.2 Backend Architecture

#### 3.2.1 Component Overview

```
app/
├── Console/Commands/
│   ├── CreatePlatformManager.php        # PM-F06: interactive create
│   ├── SetPlatformManager.php           # PM-F06: set flag by email
│   └── RevokePlatformManager.php        # PM-F06: revoke flag by email
├── Http/
│   ├── Controllers/Platform/
│   │   ├── PlatformDashboardController.php    # PM-F07
│   │   ├── PlatformOrganizationController.php # PM-F01, PM-F02, PM-F03, PM-F04
│   │   ├── PlatformUserController.php         # PM-F05
│   │   └── PlatformAuditLogController.php     # PM-F08
│   ├── Middleware/
│   │   └── EnsurePlatformManager.php          # PM-NF01
│   └── Requests/Platform/
│       ├── UpdateOrganizationStatusRequest.php
│       ├── UpdateOrganizationSettingsRequest.php
│       ├── PlatformCreateUserRequest.php
│       ├── PlatformUpdateUserRequest.php
│       └── PlatformSetManagerRequest.php
├── Models/
│   └── PlatformAuditLog.php
├── Services/
│   └── PlatformAuditService.php
└── Scopes/
    └── OrganizationScope.php              # MODIFIED: add bypass mechanism
```

#### 3.2.2 OrganizationScope Bypass Mechanism

The existing `OrganizationScope` must support an explicit, controlled bypass for platform manager queries. The bypass is implemented as a static method on the scope itself, ensuring it is always deliberate and never accidental.

**Design:**

```php
// In OrganizationScope.php — additions only, existing code untouched

class OrganizationScope implements Scope
{
    /**
     * Thread-local flag to bypass scope for current query.
     * Uses a counter to support nested bypass calls.
     */
    private static int $bypassCount = 0;

    /**
     * Execute a callback with the organization scope bypassed.
     * The scope is restored after the callback completes, even on exception.
     */
    public static function bypass(callable $callback): mixed
    {
        self::$bypassCount++;
        try {
            return $callback();
        } finally {
            self::$bypassCount--;
        }
    }

    /**
     * Check if scope is currently bypassed.
     */
    public static function isBypassed(): bool
    {
        return self::$bypassCount > 0;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::isBypassed()) {
            return; // Skip scope application
        }

        // ... existing scope logic unchanged ...
    }
}
```

**Usage in platform controllers:**

```php
// In PlatformOrganizationController
$organizations = OrganizationScope::bypass(function () {
    return Organization::withCount(['users', 'extensions', 'dids'])
        ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
        ->when($request->status, fn ($q, $s) => $q->where('status', $s))
        ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc')
        ->paginate($request->per_page ?? 25);
});
```

#### 3.2.3 Middleware: EnsurePlatformManager

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_platform_manager) {
            return response()->json([
                'message' => 'Forbidden. Platform manager access required.',
            ], 403);
        }

        return $next($request);
    }
}
```

#### 3.2.4 PlatformAuditService

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

class PlatformAuditService
{
    public function log(
        Request $request,
        string $action,
        ?int $targetOrganizationId = null,
        ?string $targetEntityType = null,
        ?int $targetEntityId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $reason = null,
    ): PlatformAuditLog {
        return PlatformAuditLog::create([
            'platform_manager_user_id' => $request->user()->id,
            'target_organization_id' => $targetOrganizationId,
            'action' => $action,
            'target_entity_type' => $targetEntityType,
            'target_entity_id' => $targetEntityId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
```

#### 3.2.5 PlatformAuditLog Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditLog extends Model
{
    protected $fillable = [
        'platform_manager_user_id',
        'target_organization_id',
        'action',
        'target_entity_type',
        'target_entity_id',
        'before_state',
        'after_state',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
    ];

    public function platformManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_manager_user_id');
    }

    public function targetOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_organization_id');
    }
}
```

#### 3.2.6 User Model Changes

Additions to the existing `User` model (no existing code modified):

```php
// Add to $casts array:
'is_platform_manager' => 'boolean',

// Add to $hidden array:
// (is_platform_manager is NOT hidden — it is included in user responses)

// IMPORTANT: Do NOT add 'is_platform_manager' to $fillable

// Add method:
public function isPlatformManager(): bool
{
    return $this->is_platform_manager === true;
}

// Add relationship:
public function platformAuditLogs(): HasMany
{
    return $this->hasMany(PlatformAuditLog::class, 'platform_manager_user_id');
}
```

#### 3.2.7 Token Abilities Update

In `AuthController`, the token abilities for platform managers include additional `platform:*` abilities:

```php
// In AuthController — modify token creation logic
$abilities = self::TOKEN_ABILITIES[$user->role->value] ?? ['basic'];

if ($user->isPlatformManager()) {
    $abilities = array_merge($abilities, [
        'platform:read',
        'platform:write',
        'platform:manage-users',
        'platform:manage-organizations',
        'platform:audit-logs',
    ]);
}

$token = $user->createToken('auth-token', $abilities);
```

### 3.3 API Design

#### 3.3.1 Route Registration

All platform management routes are registered in a dedicated route file `routes/platform.php`, included from `routes/api.php`:

```php
// In routes/api.php — add at the end:
require __DIR__.'/platform.php';
```

```php
// routes/platform.php
<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformOrganizationController;
use App\Http\Controllers\Platform\PlatformUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/platform')
    ->middleware(['auth:sanctum', 'platform.manager'])
    ->group(function () {

        // Dashboard
        Route::get('/dashboard', [PlatformDashboardController::class, 'index']);

        // Organizations
        Route::get('/organizations', [PlatformOrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [PlatformOrganizationController::class, 'show']);
        Route::put('/organizations/{organization}', [PlatformOrganizationController::class, 'update']);
        Route::patch('/organizations/{organization}/status', [PlatformOrganizationController::class, 'updateStatus']);

        // Users (across organizations)
        Route::get('/users', [PlatformUserController::class, 'index']);
        Route::get('/organizations/{organization}/users', [PlatformUserController::class, 'indexByOrganization']);
        Route::post('/organizations/{organization}/users', [PlatformUserController::class, 'store']);
        Route::get('/users/{user}', [PlatformUserController::class, 'show']);
        Route::put('/users/{user}', [PlatformUserController::class, 'update']);
        Route::delete('/users/{user}', [PlatformUserController::class, 'destroy']);
        Route::patch('/users/{user}/platform-manager', [PlatformUserController::class, 'setPlatformManager']);

        // Audit Logs
        Route::get('/audit-logs', [PlatformAuditLogController::class, 'index']);
    });
```

#### 3.3.2 Route Model Binding Override

Because platform routes operate across organizations, the route model binding for `{organization}` and `{user}` must bypass `OrganizationScope`. This is handled in each controller method by using `OrganizationScope::bypass()` for model resolution, or by using a custom route model binding in the route service provider:

```php
// In RouteServiceProvider (or AppServiceProvider boot method)
// Add explicit binding for platform routes that bypasses org scope

Route::bind('organization', function (string $value) {
    if (request()->is('api/v1/platform/*')) {
        return OrganizationScope::bypass(
            fn () => \App\Models\Organization::findOrFail($value)
        );
    }
    return \App\Models\Organization::findOrFail($value);
});
```

For user bindings in platform routes, the same pattern applies — the controllers use `OrganizationScope::bypass()` explicitly.

### 3.4 Frontend Architecture

#### 3.4.1 Component Tree

```
frontend/src/
├── components/
│   └── platform/
│       ├── PlatformManagerRoute.tsx        # Route guard component
│       ├── PlatformSidebar.tsx             # Platform management sidebar
│       ├── PlatformLayout.tsx              # Layout wrapper for platform pages
│       ├── OrganizationStatusBadge.tsx     # Status badge component
│       └── AuditLogEntry.tsx               # Single audit log entry display
├── pages/
│   └── platform/
│       ├── PlatformDashboard.tsx           # PM-F07
│       ├── PlatformOrganizations.tsx       # PM-F01
│       ├── PlatformOrganizationDetail.tsx  # PM-F02, PM-F03, PM-F04
│       ├── PlatformUsers.tsx              # PM-F05 (cross-org)
│       ├── PlatformUserDetail.tsx         # PM-F05 (single user)
│       └── PlatformAuditLog.tsx           # PM-F08
├── hooks/
│   └── platform/
│       ├── usePlatformDashboard.ts
│       ├── usePlatformOrganizations.ts
│       ├── usePlatformOrganization.ts
│       ├── usePlatformUsers.ts
│       ├── usePlatformUser.ts
│       └── usePlatformAuditLogs.ts
├── services/
│   └── platformApi.ts                      # API client for platform endpoints
└── types/
    └── platform.ts                         # TypeScript types for platform entities
```

#### 3.4.2 PlatformManagerRoute Guard

```tsx
// frontend/src/components/platform/PlatformManagerRoute.tsx

import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';

export function PlatformManagerRoute() {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return <div>Loading...</div>;
  }

  if (!user || !user.is_platform_manager) {
    return <Navigate to="/ui/dashboard" replace />;
  }

  return <Outlet />;
}
```

#### 3.4.3 TypeScript Types

```typescript
// frontend/src/types/platform.ts

export interface PlatformOrganization {
  id: number;
  name: string;
  slug: string;
  status: 'active' | 'suspended' | 'deleted';
  timezone: string;
  settings: Record<string, unknown>;
  users_count: number;
  extensions_count: number;
  dids_count: number;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface PlatformOrganizationDetail extends PlatformOrganization {
  users: PlatformUser[];
  ring_groups_count: number;
  business_hours_count: number;
}

export interface PlatformUser {
  id: number;
  organization_id: number;
  organization_name: string;
  name: string;
  email: string;
  role: 'owner' | 'pbx_admin' | 'pbx_user' | 'reporter';
  status: 'active' | 'inactive';
  is_platform_manager: boolean;
  phone: string | null;
  created_at: string;
  updated_at: string;
}

export interface PlatformAuditLogEntry {
  id: number;
  platform_manager_user_id: number;
  platform_manager: {
    id: number;
    name: string;
    email: string;
  };
  target_organization_id: number | null;
  target_organization: {
    id: number;
    name: string;
    slug: string;
  } | null;
  action: string;
  target_entity_type: string | null;
  target_entity_id: number | null;
  before_state: Record<string, unknown> | null;
  after_state: Record<string, unknown> | null;
  reason: string | null;
  ip_address: string;
  user_agent: string;
  created_at: string;
}

export interface PlatformDashboardStats {
  organizations: {
    total: number;
    active: number;
    suspended: number;
    deleted: number;
  };
  users: {
    total: number;
    active: number;
    inactive: number;
    platform_managers: number;
  };
  extensions: {
    total: number;
  };
  dids: {
    total: number;
  };
  recent_organizations: PlatformOrganization[];
  recent_audit_logs: PlatformAuditLogEntry[];
}

export interface PlatformOrganizationsParams {
  search?: string;
  status?: 'active' | 'suspended' | 'deleted';
  sort_by?: 'name' | 'created_at' | 'users_count';
  sort_dir?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface PlatformUsersParams {
  search?: string;
  organization_id?: number;
  role?: string;
  status?: 'active' | 'inactive';
  is_platform_manager?: boolean;
  sort_by?: 'name' | 'email' | 'created_at';
  sort_dir?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface PlatformAuditLogParams {
  platform_manager_user_id?: number;
  target_organization_id?: number;
  action?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}
```

#### 3.4.4 Platform API Service

```typescript
// frontend/src/services/platformApi.ts

import api from '@/services/api';
import type {
  PlatformOrganization,
  PlatformOrganizationDetail,
  PlatformUser,
  PlatformAuditLogEntry,
  PlatformDashboardStats,
  PlatformOrganizationsParams,
  PlatformUsersParams,
  PlatformAuditLogParams,
} from '@/types/platform';

const PLATFORM_BASE = '/api/v1/platform';

export const platformApi = {
  // Dashboard
  getDashboard: () =>
    api.get<PlatformDashboardStats>(`${PLATFORM_BASE}/dashboard`),

  // Organizations
  getOrganizations: (params?: PlatformOrganizationsParams) =>
    api.get<{ data: PlatformOrganization[]; meta: any }>(`${PLATFORM_BASE}/organizations`, { params }),

  getOrganization: (id: number) =>
    api.get<PlatformOrganizationDetail>(`${PLATFORM_BASE}/organizations/${id}`),

  updateOrganization: (id: number, data: Partial<Pick<PlatformOrganization, 'name' | 'timezone' | 'settings'>>) =>
    api.put<PlatformOrganization>(`${PLATFORM_BASE}/organizations/${id}`, data),

  updateOrganizationStatus: (id: number, data: { status: string; reason?: string }) =>
    api.patch<PlatformOrganization>(`${PLATFORM_BASE}/organizations/${id}/status`, data),

  // Users
  getUsers: (params?: PlatformUsersParams) =>
    api.get<{ data: PlatformUser[]; meta: any }>(`${PLATFORM_BASE}/users`, { params }),

  getOrganizationUsers: (orgId: number, params?: PlatformUsersParams) =>
    api.get<{ data: PlatformUser[]; meta: any }>(`${PLATFORM_BASE}/organizations/${orgId}/users`, { params }),

  getUser: (id: number) =>
    api.get<PlatformUser>(`${PLATFORM_BASE}/users/${id}`),

  createUser: (orgId: number, data: Partial<PlatformUser> & { password: string }) =>
    api.post<PlatformUser>(`${PLATFORM_BASE}/organizations/${orgId}/users`, data),

  updateUser: (id: number, data: Partial<PlatformUser>) =>
    api.put<PlatformUser>(`${PLATFORM_BASE}/users/${id}`, data),

  deleteUser: (id: number) =>
    api.delete(`${PLATFORM_BASE}/users/${id}`),

  setPlatformManager: (userId: number, data: { is_platform_manager: boolean }) =>
    api.patch<PlatformUser>(`${PLATFORM_BASE}/users/${userId}/platform-manager`, data),

  // Audit Logs
  getAuditLogs: (params?: PlatformAuditLogParams) =>
    api.get<{ data: PlatformAuditLogEntry[]; meta: any }>(`${PLATFORM_BASE}/audit-logs`, { params }),
};
```

#### 3.4.5 Sidebar Integration

The existing sidebar receives a conditional "Platform" section when the user is a platform manager:

```tsx
// Addition to sidebar navigation configuration
// Only shown when user.is_platform_manager === true

const platformNavSection: NavSection = {
  title: 'Platform',
  items: [
    {
      title: 'Dashboard',
      url: '/ui/platform/dashboard',
      icon: LayoutDashboard,
    },
    {
      title: 'Organizations',
      url: '/ui/platform/organizations',
      icon: Building2,
    },
    {
      title: 'All Users',
      url: '/ui/platform/users',
      icon: Users,
    },
    {
      title: 'Activity Log',
      url: '/ui/platform/audit-log',
      icon: ScrollText,
    },
  ],
};
```

#### 3.4.6 Router Configuration

```tsx
// Addition to router.tsx

const PlatformDashboard = lazy(() => import('@/pages/platform/PlatformDashboard'));
const PlatformOrganizations = lazy(() => import('@/pages/platform/PlatformOrganizations'));
const PlatformOrganizationDetail = lazy(() => import('@/pages/platform/PlatformOrganizationDetail'));
const PlatformUsers = lazy(() => import('@/pages/platform/PlatformUsers'));
const PlatformUserDetail = lazy(() => import('@/pages/platform/PlatformUserDetail'));
const PlatformAuditLog = lazy(() => import('@/pages/platform/PlatformAuditLog'));

// Inside route configuration:
{
  path: 'platform',
  element: <PlatformManagerRoute />,
  children: [
    { path: 'dashboard', element: <PlatformDashboard /> },
    { path: 'organizations', element: <PlatformOrganizations /> },
    { path: 'organizations/:id', element: <PlatformOrganizationDetail /> },
    { path: 'users', element: <PlatformUsers /> },
    { path: 'users/:id', element: <PlatformUserDetail /> },
    { path: 'audit-log', element: <PlatformAuditLog /> },
  ],
}
```

#### 3.4.7 AuthContext Changes

The `User` type in the frontend must be extended to include `is_platform_manager`:

```typescript
// In the existing User type definition (e.g., types/auth.ts or AuthContext.tsx)
// Add to the User interface:

interface User {
  // ... existing fields ...
  is_platform_manager: boolean;
}
```

The `AuthContext` does not require logic changes — it just passes through the field from the API response. The `is_platform_manager` field is included in the `/api/v1/auth/me` response because it is a column on the user model and is not in `$hidden`.

---

