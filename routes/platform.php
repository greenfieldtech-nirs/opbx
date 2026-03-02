<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Manager Routes
|--------------------------------------------------------------------------
|
| These routes provide cross-tenant administrative capabilities for
| platform managers. All routes require both authentication and the
| platform manager flag.
|
*/

Route::prefix('api/v1/platform')
    ->middleware(['auth:sanctum', 'platform.manager'])
    ->group(function (): void {
        // Dashboard
        // Route::get('/dashboard', [\App\Http\Controllers\Platform\PlatformDashboardController::class, 'index']);

        // Organizations
        // Route::get('/organizations', [\App\Http\Controllers\Platform\PlatformOrganizationController::class, 'index']);
        // Route::get('/organizations/{organization}', [\App\Http\Controllers\Platform\PlatformOrganizationController::class, 'show']);
        // Route::put('/organizations/{organization}', [\App\Http\Controllers\Platform\PlatformOrganizationController::class, 'update']);
        // Route::patch('/organizations/{organization}/status', [\App\Http\Controllers\Platform\PlatformOrganizationController::class, 'updateStatus']);

        // Users (across organizations)
        // Route::get('/users', [\App\Http\Controllers\Platform\PlatformUserController::class, 'index']);
        // Route::get('/organizations/{organization}/users', [\App\Http\Controllers\Platform\PlatformUserController::class, 'indexByOrganization']);
        // Route::post('/organizations/{organization}/users', [\App\Http\Controllers\Platform\PlatformUserController::class, 'store']);
        // Route::get('/users/{user}', [\App\Http\Controllers\Platform\PlatformUserController::class, 'show']);
        // Route::put('/users/{user}', [\App\Http\Controllers\Platform\PlatformUserController::class, 'update']);
        // Route::delete('/users/{user}', [\App\Http\Controllers\Platform\PlatformUserController::class, 'destroy']);
        // Route::patch('/users/{user}/platform-manager', [\App\Http\Controllers\Platform\PlatformUserController::class, 'setPlatformManager']);

        // Audit Logs
        // Route::get('/audit-logs', [\App\Http\Controllers\Platform\PlatformAuditLogController::class, 'index']);
    });
