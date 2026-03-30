<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\DestinationStatus;
use App\Http\Controllers\Controller;
use App\Models\AutoDialerCallSession;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Dialer Webhook Proxy Controller
 *
 * Handles Cloudonix webhooks for auto-dialer campaigns.
 * Validates signatures and updates call/session status.
 *
 * @see https://developers.cloudonix.com/Documentation/make.com/webhooks
 */
class DialerWebhookProxyController extends Controller
{
    /**
     * Handle Cloudonix webhooks for auto-dialer calls.
     *
     * This endpoint receives webhooks from Cloudonix, validates them,
     * and updates the call session and destination status.
     *
     * Expected webhook types:
     * - call.initiated: Call is being initiated
     * - call.ringing: Call is ringing
     * - call.answered: Call was answered
     * - call.completed: Call completed
     * - call.failed: Call failed
     * - call.busy: Line was busy
     * - call.no-answer: No answer
     */
    public function handleCloudonixWebhook(Request $request): JsonResponse
    {
        $eventType = $request->input('type', 'unknown');
        $callId = $request->input('call_id');
        $sessionToken = $request->input('session_token');
        $customData = $request->input('custom_data', []);

        // Log the webhook receipt
        Log::info('Dialer webhook received', [
            'event_type' => $eventType,
            'call_id' => $callId,
            'session_token' => $sessionToken,
            'organization_id' => $request->input('_organization_id'),
        ]);

        // Extract campaign and destination info from custom data
        $campaignId = $customData['campaign_id'] ?? null;
        $destinationId = $customData['destination_id'] ?? null;
        $workerId = $customData['worker_id'] ?? null;

        // Find the session - either by session_token or by call_id
        $session = $this->findSession($sessionToken, $callId);

        Log::debug('Dialer webhook session lookup', [
            'session_token' => $sessionToken,
            'call_id' => $callId,
            'session_found' => $session ? true : false,
            'session_id' => $session ? $session->id : null,
        ]);

        if (! $session && ! $this->isValidEventType($eventType)) {
            // Unknown event type and no session found
            return response()->json([
                'status' => 'ignored',
                'reason' => 'unknown_event_type',
            ]);
        }

        // Process based on event type
        return $this->processWebhookEvent($request, $session);
    }

    /**
     * Process the webhook event based on type.
     *
     * All operations are wrapped in OrganizationScope::bypass() because
     * webhooks don't have authenticated users but need to access all organizations' data.
     */
    private function processWebhookEvent(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        return OrganizationScope::bypass(function () use ($request, $session): JsonResponse {
            $eventType = $request->input('type');

            return match ($eventType) {
                'call.initiated', 'call.ringing' => $this->handleCallInitiated($request, $session),
                'call.answered', 'call.connected' => $this->handleCallAnswered($request, $session),
                'call.completed' => $this->handleCallCompleted($request, $session),
                'call.failed', 'call.busy', 'call.no-answer' => $this->handleCallFailed($request, $session),
                'amd.completed' => $this->handleAmdCompleted($request, $session),
                default => $this->handleUnknownEvent($request),
            };
        });
    }

    /**
     * Handle call initiated/ringing event.
     */
    private function handleCallInitiated(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        $session->update([
            'status' => 'ringing',
            'call_id' => $request->input('call_id'),
        ]);

        Log::info('Dialer call initiated/ringing', [
            'session_id' => $session->id,
            'call_id' => $request->input('call_id'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call answered event.
     */
    private function handleCallAnswered(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        $session->markAsAnswered();

        // Update destination
        $destination = $session->destination;
        if ($destination) {
            $destination->update([
                'status' => DestinationStatus::CONNECTED,
                'last_call_id' => $request->input('call_id'),
            ]);
        }

        // Update campaign stats
        $campaign = $session->campaign;
        if ($campaign) {
            $campaign->increment('completed_calls');
        }

        Log::info('Dialer call answered', [
            'session_id' => $session->id,
            'call_id' => $request->input('call_id'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call completed event.
     */
    private function handleCallCompleted(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        $disposition = $request->input('disposition');
        $duration = $request->input('duration', 0);
        $billsec = $request->input('billsec', 0);

        $session->update([
            'status' => 'completed',
            'disposition' => $disposition,
            'duration' => $duration,
            'billsec' => $billsec,
            'completed_at' => now(),
        ]);

        // Update destination based on disposition
        $this->updateDestinationFromDisposition($session, $disposition, $duration, $billsec);

        Log::info('Dialer call completed', [
            'session_id' => $session->id,
            'disposition' => $disposition,
            'duration' => $duration,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call failed/busy/no-answer events.
     */
    private function handleCallFailed(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        $eventType = $request->input('type');
        $disposition = match ($eventType) {
            'call.busy' => 'busy',
            'call.no-answer' => 'no-answer',
            default => $request->input('disposition', 'failed'),
        };

        $session->update([
            'status' => 'failed',
            'disposition' => $disposition,
            'completed_at' => now(),
        ]);

        // Update destination based on disposition (with retry logic)
        $this->updateDestinationFromDisposition($session, $disposition, 0, 0);

        Log::info('Dialer call failed', [
            'session_id' => $session->id,
            'disposition' => $disposition,
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle AMD (Answering Machine Detection) completed event.
     */
    private function handleAmdCompleted(Request $request, ?AutoDialerCallSession $session): JsonResponse
    {
        if (! $session) {
            return response()->json(['status' => 'error', 'message' => 'Session not found'], 404);
        }

        $result = $request->input('result'); // 'human', 'machine', 'unknown'
        $confidence = $request->input('confidence');

        $session->setAmdResult($result, $confidence);

        Log::info('Dialer AMD result', [
            'session_id' => $session->id,
            'result' => $result,
            'confidence' => $confidence,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle unknown event types.
     */
    private function handleUnknownEvent(Request $request): JsonResponse
    {
        Log::warning('Unknown dialer webhook event type', [
            'type' => $request->input('type'),
            'call_id' => $request->input('call_id'),
        ]);

        return response()->json([
            'status' => 'ignored',
            'reason' => 'unknown_event_type',
        ]);
    }

    /**
     * Find session by token or call ID.
     *
     * Bypasses organization scope since webhooks don't have authenticated users.
     */
    private function findSession(?string $sessionToken, ?string $callId): ?AutoDialerCallSession
    {
        return OrganizationScope::bypass(function () use ($sessionToken, $callId): ?AutoDialerCallSession {
            $query = AutoDialerCallSession::with(['destination', 'campaign']);

            if ($sessionToken) {
                $session = $query->where('session_token', $sessionToken)->first();
                if ($session) {
                    return $session;
                }
            }

            if ($callId) {
                return $query->where('call_id', $callId)->first();
            }

            return null;
        });
    }

    /**
     * Check if event type is valid/known.
     */
    private function isValidEventType(string $eventType): bool
    {
        return in_array($eventType, [
            'call.initiated',
            'call.ringing',
            'call.answered',
            'call.connected',
            'call.completed',
            'call.failed',
            'call.busy',
            'call.no-answer',
            'amd.completed',
        ], true);
    }

    /**
     * Update destination based on call disposition.
     *
     * Implements retry logic with exponential backoff for retryable dispositions.
     */
    private function updateDestinationFromDisposition(
        AutoDialerCallSession $session,
        string $disposition,
        int $duration,
        int $billsec
    ): void {
        $destination = $session->destination;
        $campaign = $session->campaign;

        if (! $destination || ! $campaign) {
            return;
        }

        $destinationData = [
            'last_disposition' => $disposition,
            'duration' => $duration,
            'billsec' => $billsec,
            'last_call_id' => $session->call_id,
        ];

        // Map disposition to status
        $completedDispositions = ['answered', 'completed'];
        $failedDispositions = ['busy', 'no-answer', 'failed', 'cancelled', 'congestion'];

        if (in_array($disposition, $completedDispositions, true)) {
            $destinationData['status'] = DestinationStatus::COMPLETED;
        } elseif (in_array($disposition, $failedDispositions, true)) {
            // Check if should retry
            $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

            if (in_array($disposition, $retryableDispositions, true) &&
                $destination->dial_attempts < $campaign->max_dial_attempts) {
                // Calculate next retry time (exponential backoff)
                $nextRetry = $this->calculateNextRetry($destination->dial_attempts);
                $destinationData['next_retry_at'] = $nextRetry;
                $destinationData['status'] = DestinationStatus::PENDING;

                Log::info('Dialer destination scheduled for retry', [
                    'destination_id' => $destination->id,
                    'attempts' => $destination->dial_attempts,
                    'next_retry_at' => $nextRetry,
                ]);
            } else {
                $destinationData['status'] = DestinationStatus::FAILED;
                $campaign->increment('failed_calls');
            }
        }

        $destination->update($destinationData);
    }

    /**
     * Calculate next retry time using exponential backoff.
     *
     * Schedule:
     * - Attempt 1: 5 minutes
     * - Attempt 2: 10 minutes
     * - Attempt 3: 20 minutes
     * - Attempt 4: 40 minutes
     * - Attempt 5+: 60 minutes (cap)
     */
    private function calculateNextRetry(int $attemptNumber): string
    {
        $baseDelay = 5; // 5 minutes
        $delay = $baseDelay * (2 ** ($attemptNumber - 1));

        // Cap at 60 minutes
        if ($delay > 60) {
            $delay = 60;
        }

        return now()->addMinutes($delay)->toIso8601String();
    }
}
