<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessHoursSchedule;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * Business Hours Schedule Cache Observer
 *
 * Invalidates voice routing cache when business hours schedules are updated or deleted.
 * Phase 1 Step 8: Redis Caching Layer - Cache Invalidation
 */
class BusinessHoursScheduleCacheObserver
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
     * Handle the BusinessHoursSchedule "updated" event.
     *
     * @param BusinessHoursSchedule $schedule
     * @return void
     */
    public function updated(BusinessHoursSchedule $schedule): void
    {
        $this->invalidateScheduleCache($schedule);
    }

    /**
     * Handle the BusinessHoursSchedule "deleted" event.
     *
     * @param BusinessHoursSchedule $schedule
     * @return void
     */
    public function deleted(BusinessHoursSchedule $schedule): void
    {
        $this->invalidateScheduleCache($schedule);
    }

    /**
     * Invalidate cache for the business hours schedule
     *
     * @param BusinessHoursSchedule $schedule
     * @return void
     */
    private function invalidateScheduleCache(BusinessHoursSchedule $schedule): void
    {
        $this->cache->invalidateBusinessHoursSchedule($schedule->organization_id);
    }
}
