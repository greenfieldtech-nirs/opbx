<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;

/**
 * Campaign Lifecycle Manager
 *
 * Manages campaign state transitions and auto-start functionality.
 */
class CampaignLifecycleManager
{
    public function __construct(
        private readonly DialingScheduler $scheduler,
    ) {}

    /**
     * Start a campaign and begin processing.
     */
    public function start(AutoDialerCampaign $campaign): bool
    {
        if (! $campaign->canStart()) {
            Log::warning('Cannot start campaign', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status->value,
            ]);

            return false;
        }

        // Update status
        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);

        // No Laravel dialing job: the Go dialer picks up every Active campaign (single dialer path).

        Log::info('Campaign started', [
            'campaign_id' => $campaign->id,
        ]);

        return true;
    }

    /**
     * Pause a campaign.
     */
    public function pause(AutoDialerCampaign $campaign): bool
    {
        if (! $campaign->canPause()) {
            Log::warning('Cannot pause campaign', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status->value,
            ]);

            return false;
        }

        $campaign->update([
            'status' => CampaignStatus::PAUSED,
        ]);

        Log::info('Campaign paused', [
            'campaign_id' => $campaign->id,
        ]);

        return true;
    }

    /**
     * Resume a paused campaign.
     */
    public function resume(AutoDialerCampaign $campaign): bool
    {
        if ($campaign->status !== CampaignStatus::PAUSED) {
            Log::warning('Cannot resume campaign - not paused', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status->value,
            ]);

            return false;
        }

        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
        ]);

        Log::info('Campaign resumed', [
            'campaign_id' => $campaign->id,
        ]);

        return true;
    }

    /**
     * Complete a campaign.
     */
    public function complete(AutoDialerCampaign $campaign): void
    {
        $campaign->update([
            'status' => CampaignStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        $campaign->clearRedisKeys();

        Log::info('Campaign completed', [
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Archive a campaign.
     */
    public function archive(AutoDialerCampaign $campaign): void
    {
        // Stop if active
        if ($campaign->status === CampaignStatus::ACTIVE) {
            $this->pause($campaign);
        }

        $campaign->update([
            'status' => CampaignStatus::ARCHIVED,
        ]);

        $campaign->clearRedisKeys();

        Log::info('Campaign archived', [
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Complete every Active campaign whose destinations have all reached a final state.
     *
     * The Go dialer never completes campaigns, so this runs from the scheduled check command.
     * Work remaining = a destination being dialled/connected, or pending with attempts left.
     */
    public function completeFinishedCampaigns(): int
    {
        return OrganizationScope::bypass(function (): int {
            $completed = 0;

            foreach (AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)->get() as $campaign) {
                $destinations = $campaign->destinations();
                if (! (clone $destinations)->exists()) {
                    continue;
                }

                $remaining = (clone $destinations)->where(function ($q) use ($campaign) {
                    $q->whereIn('auto_dialer_destinations.status', [DestinationStatus::DIALING, DestinationStatus::CONNECTED])
                        ->orWhere(function ($q) use ($campaign) {
                            $q->where('auto_dialer_destinations.status', DestinationStatus::PENDING)
                                ->where('auto_dialer_destinations.dial_attempts', '<', $campaign->max_dial_attempts);
                        });
                })->exists();

                if (! $remaining) {
                    $this->complete($campaign);
                    $completed++;
                }
            }

            return $completed;
        });
    }

    /**
     * Check and auto-start campaigns that are due.
     *
     * This should be called by a scheduled command every minute.
     */
    public function checkAndAutoStart(): void
    {
        // Find auto-start campaigns that are ready
        $campaigns = AutoDialerCampaign::where('auto_start', true)
            ->where('status', CampaignStatus::DRAFT)
            ->whereHas('list', function ($query) {
                $query->whereIn('status', ['ready', 'in_use']);
            })
            ->get();

        foreach ($campaigns as $campaign) {
            if ($this->shouldAutoStart($campaign)) {
                $this->start($campaign);
            }
        }
    }

    /**
     * Check if a campaign should auto-start now.
     */
    private function shouldAutoStart(AutoDialerCampaign $campaign): bool
    {
        // Check if within date range
        $now = now($campaign->timezone);
        if ($now->lt($campaign->start_date) || $now->gt($campaign->end_date)) {
            return false;
        }

        // Check if within schedule
        return $this->scheduler->isWithinSchedule($campaign);
    }

    /**
     * Get campaign status summary.
     *
     * @return array<string, mixed>
     */
    public function getStatusSummary(AutoDialerCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'can_start' => $campaign->canStart(),
            'can_pause' => $campaign->canPause(),
            'is_runnable' => $campaign->isRunnable(),
            'started_at' => $campaign->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $campaign->completed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
