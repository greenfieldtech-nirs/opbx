<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Platform\PlatformCdrArchiveController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformOperateAsController;
use App\Http\Controllers\Platform\PlatformOrganizationController;
use App\Http\Controllers\Platform\PlatformStatusController;
use App\Http\Controllers\Platform\PlatformUserController;
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

Route::prefix('v1/platform')
    ->middleware(['auth:sanctum', 'platform.manager', 'bypass.organization.scope'])
    ->group(function (): void {
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
        Route::get('/users/{user}/delete-impact', [PlatformUserController::class, 'deleteImpact']);
        Route::delete('/users/{user}', [PlatformUserController::class, 'destroy']);
        Route::patch('/users/{user}/platform-manager', [PlatformUserController::class, 'setPlatformManager']);
        Route::patch('/users/{user}/password', [PlatformUserController::class, 'updatePassword']);

        // Audit Logs
        Route::get('/audit-logs', [PlatformAuditLogController::class, 'index']);

        // Warm-tier CDR archive (6-12 months old), all organisations
        Route::get('/cdr-archive', [PlatformCdrArchiveController::class, 'index']);

        // Platform Status (component health + 24h history)
        Route::get('/status', [PlatformStatusController::class, 'index']);
        Route::post('/status/check', [PlatformStatusController::class, 'check'])->middleware('throttle:6,1');

        // Operate As Organization (platform-owner impersonation)
        Route::post('/operate-as/{organization}', [PlatformOperateAsController::class, 'start']);
        Route::delete('/operate-as', [PlatformOperateAsController::class, 'stop']);
    });
