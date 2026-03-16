<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Jobs\DialDestinationJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
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
