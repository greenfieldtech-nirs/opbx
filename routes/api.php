<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AiAssistantLoadBalancerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessHoursController;
use App\Http\Controllers\Api\CallDetailRecordController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\CallNotificationsSettingsController;
use App\Http\Controllers\Api\ConferenceRoomController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\ExtensionCloudonixController;
use App\Http\Controllers\Api\ExtensionCrudController;
use App\Http\Controllers\Api\ExtensionPasswordController;
use App\Http\Controllers\Api\InboundBlacklistController;
use App\Http\Controllers\Api\IvrMenuController;
use App\Http\Controllers\Api\OutboundWhitelistController;
use App\Http\Controllers\Api\PhoneNumberController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecordingsController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\RingGroupController;
use App\Http\Controllers\Api\SessionUpdateController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UsersController;
use Illuminate\Support\Facades\Broadcast;
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

// Broadcasting authentication routes (must be before auth middleware)
Broadcast::routes(['middleware' => ['auth:sanctum', 'tenant.scope']]);

// Public routes for external services (Cloudonix) to access audio files.
// SECURITY: This route requires HMAC-signed URLs with expiration.
// The serveMinioFile() method validates the ?expires= and ?sig= query parameters
// using HMAC-SHA256 with APP_KEY. Unsigned or expired requests are rejected with 403.
Route::get('storage/recordings/{path}', [\App\Http\Controllers\Api\RecordingsController::class, 'serveMinioFile'])
    ->name('storage.recordings.serve')
    ->where('path', '[0-9]+/.+');

// Health check routes
// Public health endpoint - returns minimal status only (no internal details)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// Detailed health checks - behind authentication to prevent internal info leakage
Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/storage/health', function () {
        try {
            $disk = Storage::disk('recordings');

            // Test connectivity
            $disk->files();

            // Test write/read
            $testFile = '.health-check-'.time();
            $testContent = 'health-check-'.now()->timestamp;
            $disk->put($testFile, $testContent);
            $readContent = $disk->get($testFile);
            $disk->delete($testFile);

            $isHealthy = ($readContent === $testContent);

            return response()->json([
                'status' => $isHealthy ? 'ok' : 'degraded',
                'writable' => $isHealthy,
                'readable' => $isHealthy,
                'timestamp' => now()->toIso8601String(),
            ], $isHealthy ? 200 : 503);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    })->name('storage.health');

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
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    })->name('websocket.health');
});

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
    // Email validation endpoint (public, rate limited)
    // Used for async email validation during registration
    Route::get('/validate-email', [EmailValidationController::class, 'validate'])
        ->middleware('throttle:10,1')
        ->name('validate-email');

    // Authentication routes (public)
    Route::prefix('auth')->group(function (): void {
        // Login with rate limiting: 5 attempts per minute per IP
        // Supports both token-based (returns token in JSON) and cookie-based (for SPA) authentication
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth')
            ->name('auth.login');

        // Registration (public, rate limited)
        Route::post('/register', [RegisterController::class, 'register'])
            ->middleware('throttle:registration')
            ->name('auth.register');

        Route::get('/register/validate', [RegisterController::class, 'validateRegistration'])
            ->name('auth.register.validate');

        // Protected authentication routes
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        });
    });

    // Public token-authenticated routes (for HTML5 audio/video elements that can't send auth headers)
    // These routes use self-authenticating tokens instead of Sanctum middleware
    Route::get('recordings/download', [RecordingsController::class, 'secureDownload'])
        ->name('recordings.secure-download');

    // Protected API routes
    Route::middleware(['auth:sanctum', 'tenant.scope', 'rate_limit_org:api'])->group(function (): void {
        // Application configuration (public to authenticated users)
        Route::get('config/application', [ConfigurationController::class, 'index'])
            ->name('config.application');

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

        // Extensions - Cloudonix sync (using ExtensionCloudonixController)
        // Must be defined BEFORE apiResource to avoid wildcard matching issues
        Route::prefix('extensions')->group(function (): void {
            Route::get('sync/compare', [ExtensionCloudonixController::class, 'compareSync'])
                ->name('extensions.sync.compare');
            Route::post('sync', [ExtensionCloudonixController::class, 'performSync'])
                ->name('extensions.sync.perform');
        });

        // Extensions - CRUD (using ExtensionCrudController)
        Route::apiResource('extensions', ExtensionCrudController::class);

        // Extensions - Password operations (using ExtensionPasswordController)
        Route::prefix('extensions/{extension}')->group(function (): void {
            Route::get('password', [ExtensionPasswordController::class, 'getPassword'])
                ->middleware(['sensitive-operations'])
                ->name('extensions.password');
            Route::put('reset-password', [ExtensionPasswordController::class, 'resetPassword'])
                ->middleware(['auth:sanctum', 'sensitive-operations'])
                ->name('extensions.reset-password');
        });

        // AI Assistant Providers
        Route::prefix('ai-assistant')->group(function (): void {
            Route::get('providers', [\App\Http\Controllers\Api\AiAssistantProviderController::class, 'index'])
                ->name('ai-assistant.providers.index');
            Route::get('providers/{provider}', [\App\Http\Controllers\Api\AiAssistantProviderController::class, 'show'])
                ->name('ai-assistant.providers.show');
            Route::get('providers/protocol/{protocol}', [\App\Http\Controllers\Api\AiAssistantProviderController::class, 'byProtocol'])
                ->name('ai-assistant.providers.by-protocol');
        });

        // Conference Rooms
        Route::apiResource('conference-rooms', ConferenceRoomController::class);

        // AI Assistants
        Route::apiResource('ai-assistants', AiAssistantController::class);

        // Ring Groups
        Route::apiResource('ring-groups', RingGroupController::class);

        // AI Assistant Load Balancers
        Route::apiResource('ai-assistant-load-balancers', AiAssistantLoadBalancerController::class);

        // IVR Menus
        Route::get('ivr-menus/voices', [IvrMenuController::class, 'getVoices'])->name('ivr-menus.voices');
        Route::apiResource('ivr-menus', IvrMenuController::class);
        Route::patch('ivr-menus/{ivrMenu}/toggle-status', [IvrMenuController::class, 'toggleStatus'])
            ->name('ivr-menus.toggle-status');

        // Business Hours
        Route::apiResource('business-hours', BusinessHoursController::class);
        Route::post('business-hours/{businessHour}/duplicate', [BusinessHoursController::class, 'duplicate'])
            ->name('business-hours.duplicate');
        Route::patch('business-hours/{businessHour}/toggle-status', [BusinessHoursController::class, 'toggleStatus'])
            ->name('business-hours.toggle-status');

        // Phone Numbers (DIDs)
        Route::apiResource('phone-numbers', PhoneNumberController::class);

        // Outbound Whitelist
        Route::patch('outbound-whitelist/{outboundWhitelist}/toggle-status', [OutboundWhitelistController::class, 'toggleStatus'])
            ->name('outbound-whitelist.toggle-status');
        Route::apiResource('outbound-whitelist', OutboundWhitelistController::class);

        // Inbound Blacklist
        Route::get('inbound-blacklist/statistics', [InboundBlacklistController::class, 'getStatistics'])
            ->name('inbound-blacklist.statistics');
        Route::get('inbound-blacklist/blocked-logs', [InboundBlacklistController::class, 'getBlockedCallLogs'])
            ->name('inbound-blacklist.blocked-logs');
        Route::patch('inbound-blacklist/{inboundBlacklist}/toggle-status', [InboundBlacklistController::class, 'toggleStatus'])
            ->name('inbound-blacklist.toggle-status');
        Route::apiResource('inbound-blacklist', InboundBlacklistController::class);

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
            Route::get('/{call_detail_record}', [CallDetailRecordController::class, 'show'])->name('call-detail-records.show');
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
            Route::get('cloudonix/outbound-trunks', [SettingsController::class, 'getOutboundTrunks'])->name('settings.cloudonix.outbound-trunks');
            Route::post('cloudonix/reveal-keys', [SettingsController::class, 'revealKeys'])
                ->middleware('sensitive-operations')
                ->name('settings.cloudonix.reveal-keys');
        });

    });

    // Session Updates - NOT rate limited (real-time polling endpoints)
    Route::middleware(['auth:sanctum', 'tenant.scope'])->prefix('session-updates')->group(function (): void {
        Route::get('/active', [SessionUpdateController::class, 'getActiveCalls'])->name('session-updates.active');
        Route::get('/active/stats', [SessionUpdateController::class, 'getActiveCallsStats'])->name('session-updates.active.stats');
        Route::get('/{sessionId}', [SessionUpdateController::class, 'getSessionDetails'])->name('session-updates.details');
        Route::delete('/{sessionId}/disconnect', [SessionUpdateController::class, 'disconnectSession'])->name('session-updates.disconnect');
    });

    // Call Notifications Settings
    Route::middleware(['auth:sanctum', 'tenant.scope', 'rate_limit_org:api'])->prefix('call-notifications')->group(function (): void {
        Route::get('/settings', [CallNotificationsSettingsController::class, 'show'])->name('call-notifications.settings.show');
        Route::post('/settings', [CallNotificationsSettingsController::class, 'store'])->name('call-notifications.settings.store');
        Route::put('/settings', [CallNotificationsSettingsController::class, 'update'])->name('call-notifications.settings.update');
        Route::delete('/settings', [CallNotificationsSettingsController::class, 'destroy'])->name('call-notifications.settings.destroy');
        Route::post('/settings/test', [CallNotificationsSettingsController::class, 'test'])->name('call-notifications.settings.test');
        Route::get('/logs', [CallNotificationsSettingsController::class, 'logs'])->name('call-notifications.logs');
        Route::get('/logs/{sessionToken}', [CallNotificationsSettingsController::class, 'sessionLogs'])->name('call-notifications.session-logs');
        Route::get('/rate-limit', [CallNotificationsSettingsController::class, 'rateLimit'])->name('call-notifications.rate-limit');
    });
});
