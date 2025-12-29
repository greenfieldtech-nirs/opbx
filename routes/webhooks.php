<?php

declare(strict_types=1);

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
        ->middleware(['webhook.signature', 'webhook.idempotency', 'rate_limit_org:webhook'])
        ->name('webhooks.cloudonix.call-initiated');

    Route::post('/call-status', [CloudonixWebhookController::class, 'callStatus'])
        ->middleware(['webhook.signature', 'webhook.idempotency', 'rate_limit_org:webhook'])
        ->name('webhooks.cloudonix.call-status');

    Route::post('/cdr', [CloudonixWebhookController::class, 'cdr'])
        ->middleware(['webhook.signature', 'webhook.idempotency', 'rate_limit_org:webhook'])
        ->name('webhooks.cloudonix.cdr');

    Route::post('/session-update', [CloudonixWebhookController::class, 'sessionUpdate'])
        ->middleware(['webhook.signature', 'rate_limit_org:webhook'])
        ->name('webhooks.cloudonix.session-update');
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

use App\Http\Controllers\Voice\VoiceRoutingController;

Route::prefix('voice')->group(function (): void {
    // Main inbound call routing endpoint
    Route::post('/route', [VoiceRoutingController::class, 'handleInbound'])
        ->middleware(['voice.webhook.auth', 'rate_limit_org:voice_routing'])
        ->name('voice.route');

    // IVR digit input callback
    Route::post('/ivr-input', [VoiceRoutingController::class, 'handleIvrInput'])
        ->middleware(['voice.webhook.auth', 'rate_limit_org:voice_routing'])
        ->name('voice.ivr-input');

    // Ring group callback for sequential routing (round robin, priority, etc.)
    Route::post('/ring-group-callback', [VoiceRoutingController::class, 'handleRingGroupCallback'])
        ->middleware(['voice.webhook.auth', 'rate_limit_org:voice_routing'])
        ->name('voice.ring-group-callback');

    // Voice routing health check
    Route::get('/health', [VoiceRoutingController::class, 'health'])
        ->name('voice.health');
});

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
