<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AiAssistantLoadBalancerController;
use App\Http\Controllers\Api\AiAssistantProviderController;
use App\Http\Controllers\Api\Auth0Controller;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessHoursController;
use App\Http\Controllers\Api\CallDetailRecordController;
use App\Http\Controllers\Api\CallNotificationsSettingsController;
use App\Http\Controllers\Api\CallTrackingAdPlatformIntegrationController;
use App\Http\Controllers\Api\CallTrackingAnalyticsController;
use App\Http\Controllers\Api\CallTrackingCampaignController;
use App\Http\Controllers\Api\CallTrackingDniController;
use App\Http\Controllers\Api\CallTrackingNotificationSettingsController;
use App\Http\Controllers\Api\CallTrackingNumberController;
use App\Http\Controllers\Api\CallTrackingSessionController;
use App\Http\Controllers\Api\ConferenceRoomController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\EmailValidationController;
use App\Http\Controllers\Api\ExtensionCloudonixController;
use App\Http\Controllers\Api\ExtensionCrudController;
use App\Http\Controllers\Api\ExtensionPasswordController;
use App\Http\Controllers\Api\InboundBlacklistController;
use App\Http\Controllers\Api\IvrMenuController;
use App\Http\Controllers\Api\OrganizationJoinRequestController;
use App\Http\Controllers\Api\OutboundWhitelistController;
use App\Http\Controllers\Api\PhoneNumberController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecordingsController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\RingGroupController;
use App\Http\Controllers\Api\SessionUpdateController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SupervisorAssignmentController;
use App\Http\Controllers\Api\SupervisorDashboardController;
use App\Http\Controllers\Api\UserInvitationController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WebPhoneConfigController;
use App\Http\Controllers\AutoDialerCampaignController;
use App\Http\Controllers\DialerWorkerController;
use App\Http\Controllers\DistributionListController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Pusher\Pusher;

/*
|--------------------------------------------------------------------------
| API Routes (Control Plane)
|--------------------------------------------------------------------------
|
| These routes handle the REST API for PBX configuration and management.
| All routes require authentication via Laravel Sanctum.
|
*/

// Public routes for external services (Cloudonix) to access audio files.
// SECURITY: This route requires HMAC-signed URLs with expiration.
// The serveMinioFile() method validates the ?expires= and ?sig= query parameters
// using HMAC-SHA256 with APP_KEY. Unsigned or expired requests are rejected with 403.
Route::get('storage/recordings/{path}', [RecordingsController::class, 'serveMinioFile'])
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

// Auto Dialer Worker API Routes (separate authentication)
// These routes are for the Go-based worker service to execute outbound campaigns
Route::prefix('v1/dialer/worker')->middleware(['dialer.worker.auth', 'throttle:dialer-worker'])->group(function (): void {
    // Health check
    Route::get('/health', [DialerWorkerController::class, 'health'])->name('dialer.worker.health');

    // Campaign management
    Route::get('/campaigns/active', [DialerWorkerController::class, 'getActiveCampaigns'])
        ->name('dialer.worker.campaigns.active');
    Route::post('/campaigns/{campaign}/pause', [DialerWorkerController::class, 'pauseCampaign'])
        ->name('dialer.worker.campaigns.pause');

    // Destination management
    Route::get('/campaigns/{campaign}/destinations/pending', [DialerWorkerController::class, 'getPendingDestinations'])
        ->name('dialer.worker.destinations.pending');
    Route::get('/campaigns/{campaign}/destinations/retry', [DialerWorkerController::class, 'getRetryDestinations'])
        ->name('dialer.worker.destinations.retry');

    // Call session management
    Route::post('/calls/initiate', [DialerWorkerController::class, 'initiateCall'])
        ->name('dialer.worker.calls.initiate');
    Route::post('/calls/generate-cxml', [DialerWorkerController::class, 'generateCxml'])
        ->name('dialer.worker.calls.generate-cxml');
    Route::patch('/calls/{session}/status', [DialerWorkerController::class, 'updateCallStatus'])
        ->name('dialer.worker.calls.status');
    Route::post('/calls/{session}/disposition', [DialerWorkerController::class, 'setDisposition'])
        ->name('dialer.worker.calls.disposition');

    // State persistence
    Route::post('/state/persist', [DialerWorkerController::class, 'persistState'])
        ->name('dialer.worker.state.persist');
    Route::get('/state/{workerId}', [DialerWorkerController::class, 'getState'])
        ->name('dialer.worker.state.get');
});

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
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    })->name('storage.health');

    Route::get('/websocket/health', function () {
        try {
            // Test Pusher/Soketi connection
            $pusher = new Pusher(
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
        } catch (Exception $e) {
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
    // Broadcasting authentication routes (for WebSocket presence channels)
    // Must be accessible to authenticated users for Laravel Echo
    Broadcast::routes(['middleware' => ['auth:sanctum', 'tenant.scope']]);

    // Email validation endpoint (public, rate limited)
    // Used for async email validation during registration
    Route::get('/validate-email', [EmailValidationController::class, 'validate'])
        ->middleware('throttle:10,1')
        ->name('validate-email');

    // Call Tracking DNI swap endpoint (public, rate limited)
    Route::get('call-tracking-dni/swap', [CallTrackingDniController::class, 'swap'])
        ->middleware(['throttle:call-tracking-dni'])
        ->name('call-tracking.dni.swap');

    // Application configuration (public)
    // Required by the login/register pages before authentication to decide whether
    // to show Auth0 social-provider buttons, reCAPTCHA, etc.
    Route::get('config/application', [ConfigurationController::class, 'index'])
        ->name('config.application');

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

        Route::post('/auth0/redirect', [Auth0Controller::class, 'redirect'])
            ->name('auth.auth0.redirect')
            ->middleware('throttle:auth');

        Route::get('/auth0/callback', [Auth0Controller::class, 'callback'])
            ->name('auth.auth0.callback')
            ->middleware('throttle:auth');

        Route::get('/register/validate', [RegisterController::class, 'validateRegistration'])
            ->name('auth.register.validate');

        // Protected authentication routes
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');

            Route::post('/auth0/link', [Auth0Controller::class, 'initiateLink'])->name('auth.auth0.link');
            Route::post('/auth0/unlink', [Auth0Controller::class, 'unlink'])->name('auth.auth0.unlink');
        });
    });

    // Organization join requests
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/organizations/join-requests', [OrganizationJoinRequestController::class, 'index'])->name('join-requests.index');
        Route::post('/organizations/join-requests/{joinRequest}/approve', [OrganizationJoinRequestController::class, 'approve'])->name('join-requests.approve');
        Route::post('/organizations/join-requests/{joinRequest}/reject', [OrganizationJoinRequestController::class, 'reject'])->name('join-requests.reject');
    });

    Route::post('/organizations/join-requests', [OrganizationJoinRequestController::class, 'store'])
        ->name('join-requests.store')
        ->middleware('throttle:auth');

    // Public token-authenticated routes (for HTML5 audio/video elements that can't send auth headers)
    // These routes use self-authenticating tokens instead of Sanctum middleware
    Route::get('recordings/download', [RecordingsController::class, 'secureDownload'])
        ->name('recordings.secure-download');

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
        Route::patch('users/{user}/password', [UsersController::class, 'updatePassword'])
            ->name('users.password.update');
        Route::post('users/invite', [UserInvitationController::class, 'invite'])
            ->name('users.invite');

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
            Route::get('providers', [AiAssistantProviderController::class, 'index'])
                ->name('ai-assistant.providers.index');
            Route::get('providers/{provider}', [AiAssistantProviderController::class, 'show'])
                ->name('ai-assistant.providers.show');
            Route::get('providers/protocol/{protocol}', [AiAssistantProviderController::class, 'byProtocol'])
                ->name('ai-assistant.providers.by-protocol');
        });

        // Conference Rooms
        Route::apiResource('conference-rooms', ConferenceRoomController::class);

        // Call Tracking Campaigns
        Route::apiResource('call-tracking-campaigns', CallTrackingCampaignController::class);

        // Call Tracking Numbers (nested under campaigns)
        Route::apiResource('call-tracking-campaigns.call-tracking-numbers', CallTrackingNumberController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Call Tracking Notification Settings (singleton nested under campaigns)
        Route::get('call-tracking-campaigns/{callTrackingCampaign}/notification-settings', [CallTrackingNotificationSettingsController::class, 'show'])
            ->name('call-tracking-campaigns.notification-settings.show');
        Route::put('call-tracking-campaigns/{callTrackingCampaign}/notification-settings', [CallTrackingNotificationSettingsController::class, 'update'])
            ->name('call-tracking-campaigns.notification-settings.update');
        Route::post('call-tracking-campaigns/{callTrackingCampaign}/notification-settings/test', [
            CallTrackingNotificationSettingsController::class, 'test',
        ])->middleware(['throttle:5,1'])
            ->name('call-tracking-campaigns.notification-settings.test');

        Route::get('call-tracking-campaigns/{callTrackingCampaign}/notification-logs', [
            CallTrackingNotificationSettingsController::class, 'logs',
        ])->name('call-tracking-campaigns.notification-logs.index');

        // Call Tracking Analytics
        Route::get('call-tracking-analytics', [CallTrackingAnalyticsController::class, 'index'])
            ->name('call-tracking-analytics.index');
        Route::get('call-tracking-analytics/export', [CallTrackingAnalyticsController::class, 'export'])
            ->name('call-tracking-analytics.export');

        // Call Tracking Sessions
        Route::get('call-tracking-sessions', [CallTrackingSessionController::class, 'index'])
            ->name('call-tracking-sessions.index');

        // Call Tracking Ad-Platform Integrations (organization singleton)
        Route::get('call-tracking-ad-platform-integrations', [
            CallTrackingAdPlatformIntegrationController::class, 'show',
        ])->name('call-tracking-ad-platform-integrations.show');

        Route::put('call-tracking-ad-platform-integrations', [
            CallTrackingAdPlatformIntegrationController::class, 'update',
        ])->name('call-tracking-ad-platform-integrations.update');

        // AI Assistants
        Route::apiResource('ai-assistants', AiAssistantController::class);

        // Ring Groups
        Route::apiResource('ring-groups', RingGroupController::class);

        // Supervisor assignments
        Route::prefix('supervisors')->group(function (): void {
            Route::get('{user}/assignments', [SupervisorAssignmentController::class, 'show'])
                ->name('supervisors.assignments.show');
            Route::put('{user}/assignments', [SupervisorAssignmentController::class, 'update'])
                ->name('supervisors.assignments.update');
        });

        // Supervisor dashboard
        Route::get('dashboard/supervisor', SupervisorDashboardController::class)
            ->name('dashboard.supervisor');

        // Web Phone config
        Route::get('webphone/config', [WebPhoneConfigController::class, 'config'])
            ->name('webphone.config');

        // AI Assistant Load Balancers
        Route::apiResource('ai-assistant-load-balancers', AiAssistantLoadBalancerController::class);

        // IVR Menus
        Route::get('ivr-menus/voices', [IvrMenuController::class, 'getVoices'])->name('ivr-menus.voices');
        Route::get('ivr-menus', [IvrMenuController::class, 'index'])->name('ivr-menus.index');
        Route::post('ivr-menus', [IvrMenuController::class, 'store'])->name('ivr-menus.store');
        Route::get('ivr-menus/{ivrMenu}', [IvrMenuController::class, 'show'])->name('ivr-menus.show');
        Route::put('ivr-menus/{ivrMenu}', [IvrMenuController::class, 'update'])->name('ivr-menus.update');
        Route::delete('ivr-menus/{ivrMenu}', [IvrMenuController::class, 'destroy'])->name('ivr-menus.destroy');
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

        // Call Detail Records (read-only)
        Route::prefix('call-detail-records')->group(function (): void {
            Route::get('/', [CallDetailRecordController::class, 'index'])->name('call-detail-records.index');
            Route::get('/export', [CallDetailRecordController::class, 'export'])->name('call-detail-records.export');
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
        });

    });

    // User invitations (public token-authenticated endpoints)
    Route::prefix('users/invite')->middleware('throttle:auth')->group(function (): void {
        Route::get('validate', [UserInvitationController::class, 'validateToken'])->name('users.invite.validate');
        Route::post('accept', [UserInvitationController::class, 'accept'])->name('users.invite.accept');
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

// Auto Dialer Campaigns — no rate limiting (high-frequency monitor polling)
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant.scope'])->group(function (): void {
    // Monitor (MUST be before apiResource to avoid 'monitor' matching {campaign})
    Route::get('auto-dialer-campaigns/monitor/summary', [AutoDialerCampaignController::class, 'monitorSummary'])
        ->name('auto-dialer-campaigns.monitor.summary');
    Route::get('auto-dialer-campaigns/{campaign}/monitor/detail', [AutoDialerCampaignController::class, 'monitorDetail'])
        ->name('auto-dialer-campaigns.monitor.detail');

    // Distribution Lists (MUST be before apiResource to avoid route conflicts)
    Route::get('auto-dialer-campaigns/lists', [DistributionListController::class, 'index'])
        ->name('distribution-lists.index');
    Route::post('auto-dialer-campaigns/lists', [DistributionListController::class, 'store'])
        ->name('distribution-lists.store');
    Route::get('auto-dialer-campaigns/lists/example-csv', [DistributionListController::class, 'downloadExample'])
        ->name('distribution-lists.example');
    Route::get('auto-dialer-campaigns/lists/{list}', [DistributionListController::class, 'show'])
        ->name('distribution-lists.show');
    Route::post('auto-dialer-campaigns/lists/{list}/upload', [DistributionListController::class, 'upload'])
        ->name('distribution-lists.upload');
    Route::post('auto-dialer-campaigns/lists/{list}/preview-csv', [DistributionListController::class, 'previewCsv'])
        ->name('distribution-lists.preview-csv');
    Route::get('auto-dialer-campaigns/lists/upload-progress/{jobId}', [DistributionListController::class, 'uploadProgress'])
        ->name('distribution-lists.progress');
    Route::post('auto-dialer-campaigns/lists/{list}/destinations', [DistributionListController::class, 'addDestination'])
        ->name('distribution-lists.destinations.add');
    Route::post('auto-dialer-campaigns/lists/{list}/destinations/batch', [DistributionListController::class, 'addDestinationsBatch'])
        ->name('distribution-lists.destinations.batch');
    Route::post('auto-dialer-campaigns/lists/{list}/destinations/{destinationId}/reset-dial-attempts', [DistributionListController::class, 'resetDialAttempts'])
        ->name('distribution-lists.destinations.reset-dial-attempts');
    Route::post('auto-dialer-campaigns/lists/{list}/destinations/bulk-reset-dial-attempts', [DistributionListController::class, 'bulkResetDialAttempts'])
        ->name('distribution-lists.destinations.bulk-reset-dial-attempts');
    Route::post('auto-dialer-campaigns/lists/{list}/reset-pending-destinations', [DistributionListController::class, 'resetPendingDestinations'])
        ->name('distribution-lists.reset-pending-destinations');
    Route::get('auto-dialer-campaigns/lists/{list}/destinations', [DistributionListController::class, 'getDestinations'])
        ->name('distribution-lists.destinations');
    Route::get('auto-dialer-campaigns/lists/{list}/versions', [DistributionListController::class, 'getVersions'])
        ->name('distribution-lists.versions');
    Route::post('auto-dialer-campaigns/lists/{list}/copy', [DistributionListController::class, 'copy'])
        ->name('distribution-lists.copy');
    Route::patch('auto-dialer-campaigns/lists/{list}/archive', [DistributionListController::class, 'archive'])
        ->name('distribution-lists.archive');
    Route::get('auto-dialer-campaigns/lists/{list}/download', [DistributionListController::class, 'download'])
        ->name('distribution-lists.download');
    Route::get('auto-dialer-campaigns/lists/{list}/validation-errors', [DistributionListController::class, 'getValidationErrors'])
        ->name('distribution-lists.errors');
    Route::delete('auto-dialer-campaigns/lists/{list}', [DistributionListController::class, 'destroy'])
        ->name('distribution-lists.destroy');
    Route::post('auto-dialer-campaigns/lists/{list}/assign', [DistributionListController::class, 'assignToCampaign'])
        ->name('distribution-lists.assign');
    Route::post('auto-dialer-campaigns/lists/{list}/unassign', [DistributionListController::class, 'unassignFromCampaign'])
        ->name('distribution-lists.unassign');

    // Campaigns - Caller ID Pooling (static routes must come BEFORE apiResource)
    Route::get('auto-dialer-campaigns/available-caller-ids', [AutoDialerCampaignController::class, 'getAvailableCallerIds'])
        ->name('auto-dialer-campaigns.available-caller-ids');

    // Campaigns CRUD + lifecycle
    Route::apiResource('auto-dialer-campaigns', AutoDialerCampaignController::class)
        ->parameters(['auto-dialer-campaigns' => 'campaign']);
    Route::patch('auto-dialer-campaigns/{campaign}/start', [AutoDialerCampaignController::class, 'start'])
        ->name('auto-dialer-campaigns.start');
    Route::patch('auto-dialer-campaigns/{campaign}/pause', [AutoDialerCampaignController::class, 'pause'])
        ->name('auto-dialer-campaigns.pause');
    Route::patch('auto-dialer-campaigns/{campaign}/resume', [AutoDialerCampaignController::class, 'resume'])
        ->name('auto-dialer-campaigns.resume');
    Route::post('auto-dialer-campaigns/{campaign}/reset-cac', [AutoDialerCampaignController::class, 'resetCac'])
        ->name('auto-dialer-campaigns.reset-cac');
    Route::patch('auto-dialer-campaigns/{campaign}/archive', [AutoDialerCampaignController::class, 'archive'])
        ->name('auto-dialer-campaigns.archive');
    Route::post('auto-dialer-campaigns/{campaign}/list', [AutoDialerCampaignController::class, 'uploadList'])
        ->name('auto-dialer-campaigns.list.upload');
    Route::get('auto-dialer-campaigns/{campaign}/list', [AutoDialerCampaignController::class, 'getList'])
        ->name('auto-dialer-campaigns.list.get');
    Route::delete('auto-dialer-campaigns/{campaign}/list', [AutoDialerCampaignController::class, 'deleteList'])
        ->name('auto-dialer-campaigns.list.delete');
    Route::get('auto-dialer-campaigns/{campaign}/destinations', [AutoDialerCampaignController::class, 'getDestinations'])
        ->name('auto-dialer-campaigns.destinations');
    Route::get('auto-dialer-campaigns/{campaign}/concurrency', [AutoDialerCampaignController::class, 'concurrency'])
        ->name('auto-dialer-campaigns.concurrency');
    Route::get('auto-dialer-campaigns/{campaign}/caller-id-stats', [AutoDialerCampaignController::class, 'getCallerIdStats'])
        ->name('auto-dialer-campaigns.caller-id-stats');
    Route::post('auto-dialer-campaigns/{campaign}/reset-caller-id-cycle', [AutoDialerCampaignController::class, 'resetCallerIdCycle'])
        ->name('auto-dialer-campaigns.reset-caller-id-cycle');
});

// Platform Manager Routes (separate file)
require __DIR__.'/platform.php';
