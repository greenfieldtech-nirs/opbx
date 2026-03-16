<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Campaign Statistics Service
 *
 * Manages campaign statistics and caching.
 */
class CampaignStatistics
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Update campaign statistics.
     */
    public function updateCounts(AutoDialerCampaign $campaign): void
    {
        $stats = $this->calculateStats($campaign);

        $campaign->update([
            'total_destinations' => $stats['total'],
            'completed_calls' => $stats['completed'],
            'failed_calls' => $stats['failed'],
            'pending_calls' => $stats['pending'],
        ]);

        // Cache the statistics
        $cacheKey = $this->getCacheKey($campaign);
        Cache::put($cacheKey, $stats, self::CACHE_TTL);

        Log::debug('Campaign statistics updated', [
            'campaign_id' => $campaign->id,
            'stats' => $stats,
        ]);
    }

    /**
     * Get campaign statistics (from cache if available).
     *
     * @return array<string, int>
     */
    public function getStats(AutoDialerCampaign $campaign): array
    {
        $cacheKey = $this->getCacheKey($campaign);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $stats = $this->calculateStats($campaign);
        Cache::put($cacheKey, $stats, self::CACHE_TTL);

        return $stats;
    }

    /**
     * Calculate statistics from database.
     *
     * @return array<string, int>
     */
    private function calculateStats(AutoDialerCampaign $campaign): array
    {
        $destinations = $campaign->destinations()->select('status')->get();

        $total = $destinations->count();
        $completed = $destinations->where('status', DestinationStatus::COMPLETED)->count();
        $failed = $destinations->where('status', DestinationStatus::FAILED)->count();
        $invalid = $destinations->where('status', DestinationStatus::INVALID)->count();
        $pending = $total - $completed - $failed - $invalid;

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'invalid' => $invalid,
            'pending' => max(0, $pending),
            'progress_percentage' => $total > 0 ? (int) round((($completed + $failed + $invalid) / $total) * 100) : 0,
        ];
    }

    /**
     * Clear cached statistics.
     */
    public function clearCache(AutoDialerCampaign $campaign): void
    {
        $cacheKey = $this->getCacheKey($campaign);
        Cache::forget($cacheKey);
    }

    /**
     * Get cache key for campaign.
     */
    private function getCacheKey(AutoDialerCampaign $campaign): string
    {
        return "auto_dialer:campaign_stats:{$campaign->id}";
    }
}
