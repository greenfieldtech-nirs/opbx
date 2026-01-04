<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessHoursController;
use App\Http\Controllers\Api\CallDetailRecordController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\ConferenceRoomController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\PhoneNumberController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecordingsController;
use App\Http\Controllers\Api\RingGroupController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\SessionUpdateController;
use App\Http\Controllers\Api\RoutingSentryController;
use App\Http\Controllers\Api\SentryBlacklistController;
use App\Http\Controllers\Api\SentryBlacklistItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Control Plane)
|--------------------------------------------------------------------------
|
| These routes handle the REST API for PBX configuration and management.
| All routes require authentication via Laravel Sanctum.
|
*/

// Health check routes (public)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'opbx-api',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

Route::get('/websocket/health', function () {
    try {
        // Test Pusher/Soketi connection
        $pusher = new \Pusher\Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.options.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        // Trigger a test event to verify connection
        $pusher->trigger('test-channel', 'test-event', ['message' => 'health-check']);

        return response()->json([
            'status' => 'ok',
            'websocket' => 'connected',
            'driver' => config('broadcasting.default'),
            'host' => config('broadcasting.connections.pusher.options.host'),
            'port' => config('broadcasting.connections.pusher.options.port'),
            'timestamp' => now()->toIso8601String(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'websocket' => 'disconnected',
            'error' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})->name('websocket.health');

// CSRF Cookie endpoint for SPA authentication
// This must be called before login when using cookie-based auth
// Uses web middleware to enable sessions and set CSRF cookie
Route::get('/sanctum/csrf-cookie', function () {
    // Access session to trigger cookie creation
    request()->session()->get('_token');
    return response()->json(['message' => 'CSRF cookie set']);
})->middleware(['web', 'throttle:60,1'])->name('sanctum.csrf-cookie');

// API Version 1 routes
Route::prefix('v1')->group(function (): void {
    // Authentication routes (public)
    Route::prefix('auth')->group(function (): void {
        // Login with rate limiting: 5 attempts per minute per IP
        // Supports both token-based (returns token in JSON) and cookie-based (for SPA) authentication
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')
            ->name('auth.login');

        // Protected authentication routes
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    // Protected API routes
    Route::middleware(['auth:sanctum', 'tenant.scope', 'rate_limit_org:api'])->group(function (): void {
        // Profile management (user-scoped, no tenant required)
        Route::prefix('profile')->group(function (): void {
            Route::get('/', [ProfileController::class, 'show'])->name('profile.show');
            Route::put('/', [ProfileController::class, 'update'])->name('profile.update');

            // Sensitive operations with stricter rate limiting
            Route::put('/password', [ProfileController::class, 'updatePassword'])
                ->middleware('throttle:sensitive')
                ->name('profile.password');
            Route::put('/organization', [ProfileController::class, 'updateOrganization'])
                ->middleware('throttle:sensitive')
                ->name('profile.organization');
        });

        // Users
        Route::apiResource('users', UsersController::class);

        // Extensions
        Route::get('extensions/sync/compare', [ExtensionController::class, 'compareSync']);
        Route::post('extensions/sync', [ExtensionController::class, 'performSync']);
        Route::get('extensions/{extension}/password', [ExtensionController::class, 'getPassword'])
            ->name('extensions.password');
        Route::put('extensions/{extension}/reset-password', [ExtensionController::class, 'resetPassword'])
            ->name('extensions.reset-password');
        Route::apiResource('extensions', ExtensionController::class);

        // Conference Rooms
        Route::apiResource('conference-rooms', ConferenceRoomController::class);

        // Ring Groups
        Route::apiResource('ring-groups', RingGroupController::class);

        // Business Hours
        Route::apiResource('business-hours', BusinessHoursController::class);
        Route::post('business-hours/{businessHour}/duplicate', [BusinessHoursController::class, 'duplicate'])
            ->name('business-hours.duplicate');

        // Phone Numbers (DIDs)
        Route::apiResource('phone-numbers', PhoneNumberController::class);

        // Call Logs (read-only)
        Route::prefix('call-logs')->group(function (): void {
            Route::get('/', [CallLogController::class, 'index'])->name('call-logs.index');
            Route::get('/active', [CallLogController::class, 'active'])->name('call-logs.active');
            Route::get('/statistics', [CallLogController::class, 'statistics'])->name('call-logs.statistics');
            Route::get('/{callLog}', [CallLogController::class, 'show'])->name('call-logs.show');
        });

        // Call Detail Records (read-only)
        Route::prefix('call-detail-records')->group(function (): void {
            Route::get('/', [CallDetailRecordController::class, 'index'])->name('call-detail-records.index');
            Route::get('/statistics', [CallDetailRecordController::class, 'statistics'])->name('call-detail-records.statistics');
            Route::get('/{callDetailRecord}', [CallDetailRecordController::class, 'show'])->name('call-detail-records.show');
        });

        // Recordings (Owner/Admin only)
        Route::apiResource('recordings', RecordingsController::class);
        Route::get('recordings/{recording}/download', [RecordingsController::class, 'download'])
            ->name('recordings.download');

        // Settings (Owner only)
        Route::prefix('settings')->group(function (): void {
            Route::get('cloudonix', [SettingsController::class, 'getCloudonixSettings'])->name('settings.cloudonix.show');
            Route::put('cloudonix', [SettingsController::class, 'updateCloudonixSettings'])->name('settings.cloudonix.update');
            Route::post('cloudonix/validate', [SettingsController::class, 'validateCloudonixCredentials'])->name('settings.cloudonix.validate');
            Route::post('cloudonix/generate-requests-key', [SettingsController::class, 'generateRequestsApiKey'])->name('settings.cloudonix.generate-key');
        });

        // Routing Sentry
        Route::prefix('sentry')->group(function (): void {
            Route::get('settings', [RoutingSentryController::class, 'getSettings'])->name('sentry.settings.show');
            Route::put('settings', [RoutingSentryController::class, 'updateSettings'])->name('sentry.settings.update');

            Route::apiResource('blacklists', SentryBlacklistController::class);
            Route::post('blacklists/{blacklist}/items', [SentryBlacklistItemController::class, 'store'])->name('sentry.blacklists.items.store');
            Route::delete('blacklists/{blacklist}/items/{item}', [SentryBlacklistItemController::class, 'destroy'])->name('sentry.blacklists.items.destroy');
        });
    });

    // Session Updates - NOT rate limited (real-time polling endpoints)
    Route::middleware(['auth:sanctum', 'tenant.scope'])->prefix('session-updates')->group(function (): void {
        Route::get('/active', [SessionUpdateController::class, 'getActiveCalls'])->name('session-updates.active');
        Route::get('/active/stats', [SessionUpdateController::class, 'getActiveCallsStats'])->name('session-updates.active.stats');
        Route::get('/{sessionId}', [SessionUpdateController::class, 'getSessionDetails'])->name('session-updates.details');
    });
});
