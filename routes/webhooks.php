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
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.call-initiated');

    Route::post('/call-status', [CloudonixWebhookController::class, 'callStatus'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.call-status');

    Route::post('/cdr', [CloudonixWebhookController::class, 'cdr'])
        ->middleware(['webhook.signature', 'webhook.idempotency'])
        ->name('webhooks.cloudonix.cdr');
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
