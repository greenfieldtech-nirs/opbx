<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\AutoDialerWebhookController;
use App\Http\Controllers\Webhooks\CloudonixWebhookController;
use App\Http\Controllers\Webhooks\DialerWebhookProxyController;
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

Route::prefix('webhooks/cloudonix')->middleware('log.callflow')->group(function (): void {
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

use App\Http\Controllers\Voice\AlbsFollowThroughController;
use App\Http\Controllers\Voice\AmdActionController;
use App\Http\Controllers\Voice\CampaignRouteController;
use App\Http\Controllers\Voice\QueueConferenceController;
use App\Http\Controllers\Voice\QueueDialCallbackController;
use App\Http\Controllers\Voice\QueueImmediateDialController;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Controllers\Voice\RingGroupConferenceController;
use App\Http\Controllers\Voice\VoiceRoutingController;

Route::prefix('voice')->middleware(['log.callflow', 'log.voice'])->group(function (): void {
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

    // AMD stream admission — worker checks the stream's session token on start
    Route::post('/amd-session', [AmdActionController::class, 'session'])
        ->name('voice.amd-session');
});

// Action-related callbacks
Route::prefix('callbacks')->middleware(['log.callflow', 'log.voice'])->group(function (): void {
    // Ring group callback for sequential routing (round robin, priority, etc.)
    Route::post('/voice/ring-group-callback', [VoiceRoutingController::class, 'handleRingGroupCallback'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.ring-group-callback');

    // Conference room PIN / host PIN digits
    Route::post('/voice/conference-pin', [VoiceRoutingController::class, 'handleConferencePin'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.conference-pin');

    // ALB follow-through callback for failover routing
    Route::post('/voice/albs-follow-through', [AlbsFollowThroughController::class, 'handle'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.albs-follow-through');

    // Call queue poll endpoint (hold loop → wait/dial/overflow decision)
    Route::post('/voice/queue-poll', [QueuePollController::class, 'handle'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.queue-poll');

    // Call queue agent dial result callback
    Route::post('/voice/queue-dial-callback', [QueueDialCallbackController::class, 'handle'])
        ->middleware(['voice.webhook.auth'])
        ->name('voice.queue-dial-callback');

    // Proactive (immediate connect) dial endpoint - HMAC-signed URL, no Bearer auth
    Route::any('/voice/queue-dial', [QueueImmediateDialController::class, 'handle'])
        ->name('voice.queue-dial');

    // Conference-mediated ring-all (HMAC-signed URLs, no Bearer auth):
    // originated agent legs claim the call; the caller joins the winner's room.
    Route::any('/voice/queue-agent-connect', [QueueConferenceController::class, 'agentConnect'])
        ->name('voice.queue-agent-connect');
    Route::any('/voice/queue-agent-join', [QueueConferenceController::class, 'agentJoin'])
        ->name('voice.queue-agent-join');
    Route::any('/voice/queue-conference-join', [QueueConferenceController::class, 'callerJoin'])
        ->name('voice.queue-conference-join');

    // Simultaneous ring groups (HMAC-signed URLs, no Bearer auth): a member's own session claims the
    // call and joins the caller's room; the caller is switched to the fallback when nobody answers.
    Route::any('/voice/ring-group-member-connect', [RingGroupConferenceController::class, 'memberConnect'])
        ->name('voice.ring-group-member-connect');
    Route::any('/voice/ring-group-fallback', [RingGroupConferenceController::class, 'fallback'])
        ->name('voice.ring-group-fallback');

    // Answered auto-dialer call routed to a ring group or call queue (signed URL, no Bearer auth).
    Route::any('/voice/campaign-route', CampaignRouteController::class)
        ->name('voice.campaign-route');
});

/*
|--------------------------------------------------------------------------
| Auto Dialer Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle webhooks specific to auto-dialer campaigns.
|
*/

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
