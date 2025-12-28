<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessHoursException;
use App\Services\VoiceRouting\VoiceRoutingCacheService;

/**
 * Business Hours Exception Cache Observer
 *
 * Invalidates voice routing cache when business hours exceptions are updated or deleted.
 * This ensures that changes to holiday/exception schedules propagate to the cache.
 *
 * Phase 1 Step 8.6: Cache Invalidation for Related Models
 */
class BusinessHoursExceptionCacheObserver
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
     * Handle the BusinessHoursException "saved" event.
     *
     * @param BusinessHoursException $exception
     * @return void
     */
    public function saved(BusinessHoursException $exception): void
    {
        $this->invalidateParentScheduleCache($exception);
    }

    /**
     * Handle the BusinessHoursException "deleted" event.
     *
     * @param BusinessHoursException $exception
     * @return void
     */
    public function deleted(BusinessHoursException $exception): void
    {
        $this->invalidateParentScheduleCache($exception);
    }

    /**
     * Invalidate cache for the parent business hours schedule
     *
     * @param BusinessHoursException $exception
     * @return void
     */
    private function invalidateParentScheduleCache(BusinessHoursException $exception): void
    {
        // Load the parent schedule if not already loaded
        if (!$exception->relationLoaded('schedule')) {
            $exception->load('schedule');
        }

        $schedule = $exception->schedule;

        if ($schedule) {
            $this->cache->invalidateBusinessHoursSchedule($schedule->organization_id);
        }
    }
}
