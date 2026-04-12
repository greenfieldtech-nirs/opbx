<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Campaign Lifecycle Service
 *
 * Handles campaign start, stop, pause, resume, archive operations and state management.
 */
class CampaignLifecycleService
{
    public function __construct(
        private readonly CampaignListService $listService,
        private readonly CampaignMonitorService $monitorService,
    ) {}

    /**
     * Start a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to start
     * @return array<string, mixed> Result with success status and message
     */
    public function start(AutoDialerCampaign $campaign): array
    {
        if (! $campaign->canStart()) {
            return [
                'success' => false,
                'message' => 'Campaign cannot be started from its current status',
                'code' => 409,
            ];
        }

        if (! $this->listService->hasValidList($campaign)) {
            return [
                'success' => false,
                'message' => 'Cannot start campaign without a destination list',
                'code' => 422,
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);

        $this->monitorService->bustMonitorCache($campaign->organization_id, $campaign->id);

        Log::info('Campaign started', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign started successfully',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Pause a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to pause
     * @return array<string, mixed> Result with success status and message
     */
    public function pause(AutoDialerCampaign $campaign): array
    {
        if (! $campaign->canPause()) {
            return [
                'success' => false,
                'message' => 'Only active campaigns can be paused',
                'code' => 409,
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::PAUSED,
        ]);

        // Clean up stale sessions
        $staleCount = $this->cleanupStaleSessions($campaign->id);

        if ($staleCount > 0) {
            Log::info('Cleaned up stale sessions on campaign pause', [
                'campaign_id' => $campaign->id,
                'stale_sessions' => $staleCount,
            ]);
        }

        // Reset CAC counter in Redis
        $this->resetCacCounter($campaign->id);

        // Bust monitor cache
        $this->monitorService->bustMonitorCache($campaign->organization_id, $campaign->id);

        Log::info('Campaign paused', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign paused successfully',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Resume a paused campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to resume
     * @return array<string, mixed> Result with success status and message
     */
    public function resume(AutoDialerCampaign $campaign): array
    {
        if ($campaign->status !== CampaignStatus::PAUSED) {
            return [
                'success' => false,
                'message' => 'Only paused campaigns can be resumed',
                'code' => 409,
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
        ]);

        // Bust monitor cache
        $this->monitorService->bustMonitorCache($campaign->organization_id, $campaign->id);

        Log::info('Campaign resumed', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign resumed successfully',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Archive a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to archive
     * @return array<string, mixed> Result with success status and message
     */
    public function archive(AutoDialerCampaign $campaign): array
    {
        // If active, pause first
        if ($campaign->status === CampaignStatus::ACTIVE) {
            $this->pause($campaign);
        }

        $campaign->update([
            'status' => CampaignStatus::ARCHIVED,
        ]);

        Log::info('Campaign archived', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign archived successfully',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Check if a campaign can be started.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to check
     */
    public function canStart(AutoDialerCampaign $campaign): bool
    {
        return $campaign->canStart() && $this->listService->hasValidList($campaign);
    }

    /**
     * Get campaign status summary.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get summary for
     * @return array<string, mixed>
     */
    public function getStatusSummary(AutoDialerCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'can_start' => $this->canStart($campaign),
            'can_pause' => $campaign->canPause(),
            'can_resume' => $campaign->status === CampaignStatus::PAUSED,
            'can_archive' => true,
            'is_runnable' => $campaign->isRunnable(),
            'started_at' => $campaign->started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Clean up stale active sessions for a campaign.
     *
     * @param  int  $campaignId  The campaign ID
     * @return int Number of sessions cleaned up
     */
    private function cleanupStaleSessions(int $campaignId): int
    {
        return OrganizationScope::bypass(function () use ($campaignId): int {
            return AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereIn('status', ['initiated', 'ringing', 'answered'])
                ->update([
                    'status' => 'failed',
                    'disposition' => 'cancelled',
                    'completed_at' => now(),
                ]);
        });
    }

    /**
     * Reset the CAC counter in Redis.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function resetCacCounter(int $campaignId): void
    {
        try {
            $dialerRedis = Redis::connection('dialer');
            $dialerRedis->set("dialer:cac:{$campaignId}:active", 0);
        } catch (\Exception $e) {
            Log::error('Failed to reset CAC counter on pause', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
