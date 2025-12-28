<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessHoursTimeRange;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * Business Hours Time Range Cache Observer
 *
 * Invalidates voice routing cache when time ranges are updated or deleted.
 * This ensures that changes to business hours time ranges propagate to the cache.
 *
 * Phase 1 Step 8.6: Cache Invalidation for Related Models
 */
class BusinessHoursTimeRangeCacheObserver
{
    /**
     * Constructor
     *
     * @param VoiceRoutingCacheService $cache
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {
    }

    /**
     * Handle the BusinessHoursTimeRange "saved" event.
     *
     * @param BusinessHoursTimeRange $timeRange
     * @return void
     */
    public function saved(BusinessHoursTimeRange $timeRange): void
    {
        $this->invalidateParentScheduleCache($timeRange);
    }

    /**
     * Handle the BusinessHoursTimeRange "deleted" event.
     *
     * @param BusinessHoursTimeRange $timeRange
     * @return void
     */
    public function deleted(BusinessHoursTimeRange $timeRange): void
    {
        $this->invalidateParentScheduleCache($timeRange);
    }

    /**
     * Invalidate cache for the parent business hours schedule
     *
     * Time ranges belong to schedule days, which belong to schedules.
     * We need to traverse up the relationship chain.
     *
     * @param BusinessHoursTimeRange $timeRange
     * @return void
     */
    private function invalidateParentScheduleCache(BusinessHoursTimeRange $timeRange): void
    {
        // Load the parent relationships if not already loaded
        if (!$timeRange->relationLoaded('scheduleDay')) {
            $timeRange->load('scheduleDay.schedule');
        } elseif ($timeRange->scheduleDay && !$timeRange->scheduleDay->relationLoaded('schedule')) {
            $timeRange->scheduleDay->load('schedule');
        }

        $scheduleDay = $timeRange->scheduleDay;

        if ($scheduleDay) {
            $schedule = $scheduleDay->schedule;

            if ($schedule) {
                $this->cache->invalidateBusinessHoursSchedule($schedule->organization_id);
            }
        }
    }
}
