<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Publishes CDR (Call Detail Record) events to Redis for the dialer worker.
 *
 * When Cloudonix sends a CDR webhook, this service publishes the event to a
 * Redis channel that the Go dialer worker subscribes to. The worker then
 * decrements the concurrency counter and removes the session from the active
 * sessions list.
 *
 * Redis Channel: cdr:completed
 * Payload Format: JSON-encoded CDR event data
 */
class CDRPublisher
{
    /**
     * The Redis channel name for CDR events.
     */
    private const CHANNEL_NAME = 'cdr:completed';

    /**
     * Publish a CDR event to Redis.
     *
     * This method is called after the CDR has been processed and saved to the
     * database. It notifies the dialer worker that a call has completed,
     * allowing the worker to decrement the concurrency counter.
     *
     * @param  string  $sessionToken  The Cloudonix session token (call_id)
     * @param  int  $campaignId  The auto-dialer campaign ID
     * @param  int  $destinationId  The destination ID that was called
     * @param  int  $sessionId  The auto-dialer session ID
     * @param  string  $disposition  The call disposition (answered, busy, no-answer, etc.)
     * @param  int  $duration  Call duration in seconds
     * @param  int  $billsec  Billable seconds
     * @param  string|null  $workerId  The worker that initiated this call (if known)
     * @return bool True if published successfully, false otherwise
     */
    public function publish(
        string $sessionToken,
        int $campaignId,
        int $destinationId,
        int $sessionId,
        string $disposition,
        int $duration = 0,
        int $billsec = 0,
        ?string $workerId = null
    ): bool {
        try {
            $payload = [
                'type' => 'call.completed',
                'session_token' => $sessionToken,
                'campaign_id' => $campaignId,
                'destination_id' => $destinationId,
                'session_id' => $sessionId,
                'disposition' => $disposition,
                'duration' => $duration,
                'billsec' => $billsec,
                'worker_id' => $workerId,
                'timestamp' => now()->toIso8601String(),
            ];

            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

            // Publish to Redis channel
            Redis::publish(self::CHANNEL_NAME, $jsonPayload);

            Log::debug('CDR event published to Redis', [
                'channel' => self::CHANNEL_NAME,
                'session_token' => $sessionToken,
                'campaign_id' => $campaignId,
                'disposition' => $disposition,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to publish CDR event to Redis', [
                'session_token' => $sessionToken,
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish a CDR event from a CallDetailRecord model.
     *
     * Convenience method that extracts the necessary data from a CDR model
     * and publishes it to Redis. This is typically called from the webhook
     * controller after the CDR has been created.
     *
     * @param  \App\Models\CallDetailRecord  $cdr  The CDR model instance
     * @param  int|null  $campaignId  The auto-dialer campaign ID (if known)
     * @param  int|null  $destinationId  The destination ID (if known)
     * @param  int|null  $sessionId  The auto-dialer session ID (if known)
     * @return bool True if published successfully, false otherwise
     */
    public function publishFromCDR(
        \App\Models\CallDetailRecord $cdr,
        ?int $campaignId = null,
        ?int $destinationId = null,
        ?int $sessionId = null
    ): bool {
        // Extract session token from CDR
        $sessionToken = $cdr->session_token ?? $cdr->call_id;

        if (! $sessionToken) {
            Log::warning('Cannot publish CDR: missing session token', [
                'cdr_id' => $cdr->id,
            ]);

            return false;
        }

        return $this->publish(
            sessionToken: $sessionToken,
            campaignId: $campaignId ?? 0,
            destinationId: $destinationId ?? 0,
            sessionId: $sessionId ?? 0,
            disposition: $cdr->disposition ?? 'unknown',
            duration: $cdr->duration ?? 0,
            billsec: $cdr->billsec ?? 0
        );
    }

    /**
     * Publish a CDR event from an auto-dialer session.
     *
     * This method looks up the auto-dialer session by its session token
     * and publishes the CDR event with all the relevant IDs.
     *
     * @param  string  $sessionToken  The Cloudonix session token
     * @param  string  $disposition  The call disposition
     * @param  int  $duration  Call duration in seconds
     * @param  int  $billsec  Billable seconds
     * @return bool True if published successfully, false otherwise
     */
    public function publishFromSessionToken(
        string $sessionToken,
        string $disposition,
        int $duration = 0,
        int $billsec = 0
    ): bool {
        try {
            // Find the auto-dialer session
            $session = \App\Models\AutoDialerCallSession::where('session_token', $sessionToken)
                ->first();

            if (! $session) {
                Log::debug('No auto-dialer session found for CDR', [
                    'session_token' => $sessionToken,
                ]);

                return false;
            }

            return $this->publish(
                sessionToken: $sessionToken,
                campaignId: $session->campaign_id,
                destinationId: $session->destination_id,
                sessionId: $session->id,
                disposition: $disposition,
                duration: $duration,
                billsec: $billsec
            );
        } catch (\Exception $e) {
            Log::error('Failed to publish CDR from session token', [
                'session_token' => $sessionToken,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
