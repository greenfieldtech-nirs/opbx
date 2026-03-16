<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Services\AutoDialer\DestinationValidator;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to dial a single destination.
 *
 * This job handles the actual dialing of a destination number,
 * including validation, Cloudonix API call, and error handling.
 */
class DialDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = [30, 60, 120];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $destinationId,
        public int $campaignId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        DestinationValidator $validator,
        CloudonixClient $cloudonixClient
    ): void {
        $destination = AutoDialerDestination::find($this->destinationId);
        $campaign = AutoDialerCampaign::find($this->campaignId);

        if (! $destination || ! $campaign) {
            Log::warning('Destination or campaign not found', [
                'destination_id' => $this->destinationId,
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        // Check if destination is still pending
        if (! $destination->status->canDial()) {
            Log::info('Destination already processed, skipping', [
                'destination_id' => $this->destinationId,
                'status' => $destination->status->value,
            ]);

            return;
        }

        // Check if campaign is still active
        if (! $campaign->status->isRunnable()) {
            Log::info('Campaign not active, skipping', [
                'campaign_id' => $this->campaignId,
                'status' => $campaign->status->value,
            ]);

            return;
        }

        // Validate destination against whitelist
        $validation = $validator->validate(
            $destination->phone_number,
            $campaign->organization_id
        );

        if (! $validation['valid']) {
            $destination->markAsInvalid($validation['error'] ?? 'Invalid destination');

            Log::info('Destination marked as invalid', [
                'destination_id' => $this->destinationId,
                'error' => $validation['error'],
            ]);

            return;
        }

        $trunk = $validation['trunk'];

        // Build Cloudonix API options
        $options = $this->buildCloudonixOptions($campaign);

        // Update destination status to dialing
        $destination->update([
            'status' => DestinationStatus::DIALING,
            'last_dialed_at' => now(),
        ]);

        try {
            // Initiate call via Cloudonix
            $result = $cloudonixClient->initiateCall(
                $campaign->caller_id,
                $destination->phone_number,
                $trunk,
                $options
            );

            if ($result) {
                // Success - update destination and create session
                $destination->update([
                    'last_session_token' => $result['sessionToken'] ?? null,
                    'last_call_id' => $result['callId'] ?? null,
                ]);
                $destination->incrementDialAttempts();

                // Create call session record
                $campaign->callSessions()->create([
                    'organization_id' => $campaign->organization_id,
                    'destination_id' => $destination->id,
                    'session_token' => $result['sessionToken'] ?? null,
                    'call_id' => $result['callId'] ?? null,
                    'status' => 'initiated',
                    'initiated_at' => now(),
                ]);

                Log::info('Call initiated successfully', [
                    'destination_id' => $this->destinationId,
                    'call_id' => $result['callId'] ?? null,
                ]);
            } else {
                // Call initiation failed
                $destination->markAsFailed('Failed to initiate call');

                Log::warning('Call initiation failed', [
                    'destination_id' => $this->destinationId,
                    'phone_number' => $destination->phone_number,
                ]);

                // Retry if attempts remaining
                $this->scheduleRetryIfNeeded($destination, $campaign);
            }
        } catch (\Exception $e) {
            Log::error('Exception while dialing destination', [
                'destination_id' => $this->destinationId,
                'error' => $e->getMessage(),
            ]);

            $destination->markAsFailed($e->getMessage());

            // Retry if attempts remaining
            $this->scheduleRetryIfNeeded($destination, $campaign);

            throw $e; // Re-throw to trigger job retry
        }
    }

    /**
     * Build Cloudonix API options from campaign configuration.
     *
     * @return array<string, mixed>
     */
    private function buildCloudonixOptions(AutoDialerCampaign $campaign): array
    {
        $options = [
            'timeout' => $campaign->dial_timeout,
            'execute' => $campaign->destination_connect,
        ];

        // Add time limit if configured
        if ($campaign->time_limit) {
            $options['timeLimit'] = $campaign->time_limit;
        }

        // Add recording options if enabled
        if ($campaign->record_calls) {
            $options['recording'] = true;
            $options['recordingStatusCallback'] = route('webhooks.auto-dialer.call-status');
            $options['recordingStatusCallbackEvent'] = 'completed';
            $options['trim'] = 'do-not-trim';
        }

        // Add AMD options if enabled
        if ($campaign->amd_enabled && $campaign->amd_mode) {
            $options['machineDetection'] = $campaign->amd_mode->value;
            $options['machineDetectionTimeout'] = $campaign->amd_timeout;
            $options['machineDetectionSpeechThreshold'] = $campaign->amd_speech_threshold;
            $options['machineDetectionSpeechEndThreshold'] = $campaign->amd_speech_end_threshold;
            $options['machineDetectionSilenceTimeout'] = $campaign->amd_silence_timeout;
        }

        return $options;
    }

    /**
     * Schedule a retry if dial attempts remaining.
     */
    private function scheduleRetryIfNeeded(
        AutoDialerDestination $destination,
        AutoDialerCampaign $campaign
    ): void {
        if ($destination->dial_attempts < $campaign->max_dial_attempts) {
            // Exponential backoff: 5 min, 15 min, 45 min
            $delay = pow(3, $destination->dial_attempts - 1) * 5 * 60;

            self::dispatch($destination->id, $campaign->id)
                ->delay($delay);

            Log::info('Scheduled retry for destination', [
                'destination_id' => $destination->id,
                'attempt' => $destination->dial_attempts,
                'delay_seconds' => $delay,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Dial destination job failed permanently', [
            'destination_id' => $this->destinationId,
            'campaign_id' => $this->campaignId,
            'exception' => $exception->getMessage(),
        ]);

        // Mark destination as failed
        $destination = AutoDialerDestination::find($this->destinationId);
        if ($destination) {
            $destination->markAsFailed('Job failed after max retries');
        }
    }
}
