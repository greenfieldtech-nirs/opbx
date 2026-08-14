<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\AutoDialerWebhookController;
use App\Http\Controllers\Webhooks\CloudonixWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes (Execution Plane)
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from Cloudonix CPaaS.
| They are public endpoints and must implement idempotency.
|
*/

Route::prefix('webhooks/cloudonix')->group(function (): void {
    Route::post('/call-initiated', [CloudonixWebhookController::class, 'callInitiated'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.call-initiated');

    Route::post('/call-status', [CloudonixWebhookController::class, 'callStatus'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.call-status');

    Route::post('/cdr', [CloudonixWebhookController::class, 'cdr'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.cdr');

    Route::post('/session-update', [CloudonixWebhookController::class, 'sessionUpdate'])
        ->middleware(['webhook.signature'])  // High-velocity endpoint - no rate limiting
        ->name('webhooks.cloudonix.session-update');

    // Not gated behind webhook.signature - see recordingStatus() docblock for why.
    Route::post('/recording-status', [CloudonixWebhookController::class, 'recordingStatus'])
        ->middleware(['webhook.idempotency'])
        ->name('webhooks.cloudonix.recording-status');
});

/*
|--------------------------------------------------------------------------
| Voice Routing Routes (Core Routing Application)
|--------------------------------------------------------------------------
|
| These routes handle real-time call routing decisions from Cloudonix.
| They return CXML (Cloudonix XML) documents that instruct the platform
| how to route calls based on organizational configuration.
|
*/

use App\Http\Controllers\Voice\AmdActionController;
use App\Http\Controllers\Voice\VoiceRoutingController;

Route::prefix('voice')->group(function (): void {
    // Main inbound call routing endpoint
    Route::post('/route', [VoiceRoutingController::class, 'handleInbound'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.route');

    // IVR digit input callback
    Route::post('/ivr-input', [VoiceRoutingController::class, 'handleIvrInput'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.ivr-input');

    // Voice routing health check
    Route::get('/health', [VoiceRoutingController::class, 'health'])
        ->name('voice.health');

    // AMD action handler — receives detection results from AMD worker
    Route::post('/amd-action', [AmdActionController::class, 'handle'])
        ->name('voice.amd-action');
});

// Action-related callbacks
Route::prefix('callbacks')->group(function (): void {
    // Ring group callback for sequential routing (round robin, priority, etc.)
    Route::post('/voice/ring-group-callback', [VoiceRoutingController::class, 'handleRingGroupCallback'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.ring-group-callback');

    // ALB follow-through callback for failover routing
    Route::post('/voice/albs-follow-through', [\App\Http\Controllers\Voice\AlbsFollowThroughController::class, 'handle'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.albs-follow-through');
});

/*
|--------------------------------------------------------------------------
| Auto Dialer Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle webhooks specific to auto-dialer campaigns.
|
*/

use App\Http\Controllers\Webhooks\DialerWebhookProxyController;

Route::prefix('webhooks/auto-dialer')->group(function (): void {
    Route::post('/call-status', [AutoDialerWebhookController::class, 'callStatus'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.auto-dialer.call-status');

    Route::post('/amd-result', [AutoDialerWebhookController::class, 'amdResult'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.auto-dialer.amd-result');
});

// Dialer Webhook Proxy Endpoint
// Receives Cloudonix webhooks and processes them for the auto-dialer
Route::post('/webhooks/cloudonix/dialer', [DialerWebhookProxyController::class, 'handleCloudonixWebhook'])
    ->middleware(['webhook.signature', 'webhook.idempotency'])
    ->name('webhooks.cloudonix.dialer');

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => DB::connection()->getDatabaseName() ? 'connected' : 'disconnected',
            'redis' => Cache::getStore() instanceof \Illuminate\Cache\RedisStore ? 'connected' : 'disconnected',
        ],
    ]);
})->name('health');
