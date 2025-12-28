<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessHoursScheduleDay;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * Business Hours Schedule Day Cache Observer
 *
 * Invalidates voice routing cache when schedule days are updated or deleted.
 * This ensures that changes to business hours days propagate to the cache.
 *
 * Phase 1 Step 8.6: Cache Invalidation for Related Models
 */
class BusinessHoursScheduleDayCacheObserver
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
     * Handle the BusinessHoursScheduleDay "saved" event.
     *
     * @param BusinessHoursScheduleDay $scheduleDay
     * @return void
     */
    public function saved(BusinessHoursScheduleDay $scheduleDay): void
    {
        $this->invalidateParentScheduleCache($scheduleDay);
    }

    /**
     * Handle the BusinessHoursScheduleDay "deleted" event.
     *
     * @param BusinessHoursScheduleDay $scheduleDay
     * @return void
     */
    public function deleted(BusinessHoursScheduleDay $scheduleDay): void
    {
        $this->invalidateParentScheduleCache($scheduleDay);
    }

    /**
     * Invalidate cache for the parent business hours schedule
     *
     * @param BusinessHoursScheduleDay $scheduleDay
     * @return void
     */
    private function invalidateParentScheduleCache(BusinessHoursScheduleDay $scheduleDay): void
    {
        // Load the parent schedule if not already loaded
        if (!$scheduleDay->relationLoaded('schedule')) {
            $scheduleDay->load('schedule');
        }

        $schedule = $scheduleDay->schedule;

        if ($schedule) {
            $this->cache->invalidateBusinessHoursSchedule($schedule->organization_id);
        }
    }
}
