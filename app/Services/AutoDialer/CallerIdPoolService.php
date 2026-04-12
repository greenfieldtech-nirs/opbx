<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCallerIdStat;
use App\Models\AutoDialerCampaign;
use App\Models\DidNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Caller ID Pool Service
 *
 * Manages Caller ID pool operations including validation, statistics, and rotation.
 */
class CallerIdPoolService
{
    /**
     * Validate that Caller ID pool DIDs belong to the organization and are active.
     *
     * @param  array<int, array<string, mixed>>  $callerIdPool  Array of DID entries with did_id
     * @param  int  $organizationId  The organization ID to validate against
     * @return array<string, mixed> Validation result with valid status and errors
     */
    public function validateCallerIdPool(array $callerIdPool, int $organizationId): array
    {
        if (empty($callerIdPool)) {
            return [
                'valid' => true,
                'invalid_dids' => [],
            ];
        }

        $didIds = array_column($callerIdPool, 'did_id');

        $validDids = DidNumber::whereIn('id', $didIds)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();

        $invalidDids = array_diff($didIds, $validDids);

        return [
            'valid' => empty($invalidDids),
            'invalid_dids' => $invalidDids,
        ];
    }

    /**
     * Sync the Caller ID pool for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to sync pool for
     * @param  array<int, array<string, mixed>>  $callerIdPool  Array of DID entries
     * @param  bool  $validate  Whether to validate DIDs before syncing
     * @return array<string, mixed> Result with success status
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function syncCallerIdPool(AutoDialerCampaign $campaign, array $callerIdPool, bool $validate = true): array
    {
        // Check if campaign is active (cannot modify pool on active campaigns)
        if ($campaign->status === CampaignStatus::ACTIVE) {
            return [
                'success' => false,
                'message' => 'Cannot modify Caller ID pool on an active campaign',
                'code' => 409,
            ];
        }

        if ($validate) {
            $validation = $this->validateCallerIdPool($callerIdPool, $campaign->organization_id);
            if (! $validation['valid']) {
                throw new \InvalidArgumentException(
                    'Some DIDs do not exist, do not belong to your organization, or are not active.'
                );
            }
        }

        DB::transaction(function () use ($campaign, $callerIdPool): void {
            // Build sync data with weights
            $syncData = [];
            foreach ($callerIdPool as $entry) {
                $syncData[$entry['did_id']] = ['weight' => $entry['weight'] ?? 1];
            }

            // Sync the pool
            $campaign->callerIds()->sync($syncData);

            // Manage stats records
            $this->syncCallerIdStats($campaign, $callerIdPool);
        });

        return [
            'success' => true,
            'message' => 'Caller ID pool updated successfully',
        ];
    }

    /**
     * Create initial Caller ID stats records for a new campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @param  array<int, array<string, mixed>>  $callerIdPool  Array of DID entries
     */
    public function createInitialStats(AutoDialerCampaign $campaign, array $callerIdPool): void
    {
        foreach ($callerIdPool as $entry) {
            AutoDialerCallerIdStat::create([
                'campaign_id' => $campaign->id,
                'did_number_id' => $entry['did_id'],
                'total_calls' => 0,
                'completed_calls' => 0,
                'failed_calls' => 0,
                'last_used_at' => null,
            ]);
        }
    }

    /**
     * Get Caller ID statistics for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get stats for
     * @return array<string, mixed> Formatted statistics
     */
    public function getCallerIdStats(AutoDialerCampaign $campaign): array
    {
        $stats = $campaign->callerIdStats()
            ->with('didNumber:id,phone_number,friendly_name')
            ->get();

        $totalCalls = $stats->sum('total_calls');

        $formattedStats = $stats->map(fn (AutoDialerCallerIdStat $stat) => [
            'did_id' => $stat->did_number_id,
            'phone_number' => $stat->didNumber?->phone_number,
            'friendly_name' => $stat->didNumber?->friendly_name,
            'total_calls' => $stat->total_calls,
            'completed_calls' => $stat->completed_calls,
            'failed_calls' => $stat->failed_calls,
            'success_rate' => $stat->success_rate,
            'last_used_at' => $stat->last_used_at?->toIso8601String(),
        ]);

        return [
            'campaign_id' => $campaign->id,
            'total_calls' => $totalCalls,
            'strategy' => $campaign->caller_id_strategy?->value,
            'stats' => $formattedStats,
        ];
    }

    /**
     * Get available DIDs for Caller ID pool selection.
     *
     * @param  int  $organizationId  The organization ID
     * @param  int|null  $excludeCampaignId  Optional campaign ID to exclude assigned DIDs
     * @return Collection<int, DidNumber>
     */
    public function getAvailableDids(int $organizationId, ?int $excludeCampaignId = null): Collection
    {
        $query = DidNumber::forOrganization($organizationId)
            ->where('status', 'active');

        if ($excludeCampaignId !== null) {
            $excludedDidIds = DB::table('auto_dialer_campaign_caller_ids')
                ->where('campaign_id', $excludeCampaignId)
                ->pluck('did_number_id')
                ->toArray();

            if (! empty($excludedDidIds)) {
                $query->whereNotIn('id', $excludedDidIds);
            }
        }

        return $query->get(['id', 'phone_number', 'friendly_name', 'status']);
    }

    /**
     * Format available DIDs for API response.
     *
     * @param  Collection<int, DidNumber>  $dids  Collection of DID numbers
     * @return array<int, array<string, mixed>>
     */
    public function formatAvailableDids(Collection $dids): array
    {
        return $dids->map(fn (DidNumber $did) => [
            'id' => $did->id,
            'phone_number' => $did->phone_number,
            'friendly_name' => $did->friendly_name,
            'status' => $did->status,
        ])->toArray();
    }

    /**
     * Reset the Caller ID cycle (Round Robin only).
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to reset
     * @return array<string, mixed> Result with success status
     */
    public function resetCallerIdCycle(AutoDialerCampaign $campaign): array
    {
        if ($campaign->status !== CampaignStatus::PAUSED) {
            return [
                'success' => false,
                'message' => 'Caller ID cycle can only be reset when campaign is PAUSED',
                'code' => 409,
            ];
        }

        $redisKey = "campaign:{$campaign->id}:caller_id_index";

        try {
            $dialerRedis = Redis::connection('dialer');
            $dialerRedis->set($redisKey, 0);

            Log::info('Caller ID cycle reset', [
                'campaign_id' => $campaign->id,
                'strategy' => $campaign->caller_id_strategy?->value,
            ]);

            return [
                'success' => true,
                'message' => 'Caller ID cycle reset successfully',
                'next_index' => 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to reset Caller ID cycle', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to reset Caller ID cycle',
                'code' => 500,
            ];
        }
    }

    /**
     * Sync Caller ID statistics records when pool changes.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @param  array<int, array<string, mixed>>  $callerIdPool  New pool configuration
     */
    private function syncCallerIdStats(AutoDialerCampaign $campaign, array $callerIdPool): void
    {
        $didIds = array_column($callerIdPool, 'did_id');

        // Get existing stats DID IDs
        $existingStatDidIds = $campaign->callerIdStats()
            ->pluck('did_number_id')
            ->toArray();

        $newDidIds = array_diff($didIds, $existingStatDidIds);
        $removedDidIds = array_diff($existingStatDidIds, $didIds);

        // Create stats records for new DIDs
        foreach ($callerIdPool as $entry) {
            if (in_array($entry['did_id'], $newDidIds, true)) {
                AutoDialerCallerIdStat::create([
                    'campaign_id' => $campaign->id,
                    'did_number_id' => $entry['did_id'],
                    'total_calls' => 0,
                    'completed_calls' => 0,
                    'failed_calls' => 0,
                    'last_used_at' => null,
                ]);
            }
        }

        // Delete stats records for removed DIDs
        if (! empty($removedDidIds)) {
            $campaign->callerIdStats()
                ->whereIn('did_number_id', $removedDidIds)
                ->delete();
        }
    }

    /**
     * Build sync data for the pivot table.
     *
     * @param  array<int, array<string, mixed>>  $callerIdPool  Pool configuration
     * @return array<int, array<string, int>>
     */
    public function buildSyncData(array $callerIdPool): array
    {
        $syncData = [];
        foreach ($callerIdPool as $entry) {
            $syncData[$entry['did_id']] = ['weight' => $entry['weight'] ?? 1];
        }

        return $syncData;
    }
}
