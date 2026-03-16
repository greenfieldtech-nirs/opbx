<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Jobs\DialDestinationJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Campaign Processor Service
 *
 * Orchestrates the execution of auto-dialer campaigns.
 */
class CampaignProcessor
{
    public function __construct(
        private readonly DestinationValidator $destinationValidator,
        private readonly DialingScheduler $dialingScheduler,
        private readonly CampaignStatistics $campaignStatistics,
    ) {}

    /**
     * Process a campaign batch.
     */
    public function process(AutoDialerCampaign $campaign): void
    {
        // Check if campaign can run
        if (! $this->canRun($campaign)) {
            Log::info('Campaign cannot run', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status->value,
            ]);

            return;
        }

        // Check scheduling constraints
        if (! $this->dialingScheduler->isWithinSchedule($campaign)) {
            Log::info('Campaign outside schedule', [
                'campaign_id' => $campaign->id,
                'current_time' => now()->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        // Check if all destinations are in final states (no pending/dialing)
        if ($this->isCampaignComplete($campaign)) {
            $this->completeCampaign($campaign);

            return;
        }

        // Get pending destinations
        $destinations = $this->getPendingDestinations($campaign);

        if ($destinations->isEmpty()) {
            // No pending destinations but still have dialing ones - wait for them
            Log::info('No pending destinations, waiting for in-flight calls', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        // Process destinations - dispatch jobs with staggered delays
        foreach ($destinations as $index => $destination) {
            $delay = $index / $campaign->calls_per_second;
            DialDestinationJob::dispatch($destination->id, $campaign->id)
                ->onQueue('auto-dialer')
                ->delay($delay);
        }

        // Update statistics
        $this->campaignStatistics->updateCounts($campaign);
    }

    /**
     * Check if campaign can run.
     */
    public function canRun(AutoDialerCampaign $campaign): bool
    {
        // Must be active
        if ($campaign->status !== CampaignStatus::ACTIVE) {
            return false;
        }

        // Must have a list
        if (! $campaign->hasList()) {
            return false;
        }

        // Must be within date range
        $now = now();
        if ($now->lt($campaign->start_date) || $now->gt($campaign->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Check if campaign is complete (all destinations in final states).
     */
    private function isCampaignComplete(AutoDialerCampaign $campaign): bool
    {
        // Count destinations not in final states (pending, dialing, connected)
        $incompleteCount = $campaign->destinations()
            ->whereNotIn('status', [
                DestinationStatus::COMPLETED,
                DestinationStatus::FAILED,
                DestinationStatus::INVALID,
            ])
            ->count();

        return $incompleteCount === 0 && $campaign->total_destinations > 0;
    }

    /**
     * Get pending destinations for dialing.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AutoDialerDestination>
     */
    private function getPendingDestinations(AutoDialerCampaign $campaign): \Illuminate\Database\Eloquent\Collection
    {
        return $campaign->destinations()
            ->whereIn('status', [DestinationStatus::PENDING, DestinationStatus::FAILED])
            ->where('dial_attempts', '<', $campaign->max_dial_attempts)
            ->orderBy('id')
            ->limit($campaign->calls_per_second * 10) // Get batch of destinations
            ->get();
    }

    /**
     * Complete a campaign.
     */
    private function completeCampaign(AutoDialerCampaign $campaign): void
    {
        $campaign->update([
            'status' => CampaignStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Campaign completed', [
            'campaign_id' => $campaign->id,
            'total_destinations' => $campaign->total_destinations,
            'completed_calls' => $campaign->completed_calls,
            'failed_calls' => $campaign->failed_calls,
        ]);
    }
}

        // Check scheduling constraints
        if (! $this->dialingScheduler->isWithinSchedule($campaign)) {
            Log::info('Campaign outside schedule', [
                'campaign_id' => $campaign->id,
                'current_time' => now()->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        // Get pending destinations
        $destinations = $this->getPendingDestinations($campaign);

        if ($destinations->isEmpty()) {
            // No more destinations to dial - mark as completed
            $this->completeCampaign($campaign);

            return;
        }

        // Process destinations
        foreach ($destinations as $destination) {
            $this->processDestination($campaign, $destination);
        }

        // Update statistics
        $this->campaignStatistics->updateCounts($campaign);
    }

    /**
     * Check if campaign can run.
     */
    public function canRun(AutoDialerCampaign $campaign): bool
    {
        // Must be active
        if ($campaign->status !== CampaignStatus::ACTIVE) {
            return false;
        }

        // Must have a list
        if (! $campaign->hasList()) {
            return false;
        }

        // Must be within date range
        $now = now();
        if ($now->lt($campaign->start_date) || $now->gt($campaign->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Get pending destinations for dialing.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AutoDialerDestination>
     */
    private function getPendingDestinations(AutoDialerCampaign $campaign): \Illuminate\Database\Eloquent\Collection
    {
        return $campaign->destinations()
            ->whereIn('status', [DestinationStatus::PENDING, DestinationStatus::FAILED])
            ->where('dial_attempts', '<', $campaign->max_dial_attempts)
            ->orderBy('id')
            ->limit($campaign->calls_per_second * 10) // Get batch of destinations
            ->get();
    }

    /**
     * Process a single destination.
     */
    private function processDestination(AutoDialerCampaign $campaign, AutoDialerDestination $destination): void
    {
        try {
            // Validate against whitelist
            $validation = $this->destinationValidator->validate(
                $destination->phone_number,
                $campaign->organization_id
            );

            if (! $validation['valid']) {
                $destination->markAsInvalid($validation['error'] ?? 'Invalid destination');
                Log::info('Destination marked as invalid', [
                    'destination_id' => $destination->id,
                    'phone_number' => $destination->phone_number,
                    'error' => $validation['error'],
                ]);

                return;
            }

            // Get trunk from validation
            $trunk = $validation['trunk'];

            // Dial the destination
            $this->dialDestination($campaign, $destination, $trunk);

        } catch (\Exception $e) {
            Log::error('Failed to process destination', [
                'destination_id' => $destination->id,
                'error' => $e->getMessage(),
            ]);

            // Mark as failed but allow retry
            $destination->markAsFailed($e->getMessage());
        }
    }

    /**
     * Dial a destination via Cloudonix API.
     */
    private function dialDestination(
        AutoDialerCampaign $campaign,
        AutoDialerDestination $destination,
        string $trunk
    ): void {
        // Build API options
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

        // Initiate call
        $result = $this->cloudonixClient->initiateCall(
            $campaign->caller_id,
            $destination->phone_number,
            $trunk,
            $options
        );

        if ($result) {
            // Update destination with session info
            $destination->update([
                'status' => DestinationStatus::DIALING,
                'last_session_token' => $result['sessionToken'] ?? null,
                'last_call_id' => $result['callId'] ?? null,
                'last_dialed_at' => now(),
            ]);

            // Increment dial attempts
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
                'destination_id' => $destination->id,
                'call_id' => $result['callId'] ?? null,
                'session_token' => $result['sessionToken'] ?? null,
            ]);
        } else {
            // Call initiation failed
            $destination->markAsFailed('Failed to initiate call');

            Log::warning('Call initiation failed', [
                'destination_id' => $destination->id,
                'phone_number' => $destination->phone_number,
            ]);
        }
    }

    /**
     * Complete a campaign.
     */
    private function completeCampaign(AutoDialerCampaign $campaign): void
    {
        $campaign->update([
            'status' => CampaignStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Campaign completed', [
            'campaign_id' => $campaign->id,
            'total_destinations' => $campaign->total_destinations,
            'completed_calls' => $campaign->completed_calls,
            'failed_calls' => $campaign->failed_calls,
        ]);
    }
}
