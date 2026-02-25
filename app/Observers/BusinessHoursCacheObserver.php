<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BusinessHoursException;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use Illuminate\Database\Eloquent\Model;

/**
 * Business Hours Cache Observer
 *
 * Consolidated observer that invalidates voice routing cache when any
 * business hours related model is updated, saved, or deleted.
 *
 * Handles:
 * - BusinessHoursSchedule (main schedule)
 * - BusinessHoursScheduleDay (days of week)
 * - BusinessHoursTimeRange (time ranges within days)
 * - BusinessHoursException (holiday/special date exceptions)
 *
 * Phase 1 Step 8: Redis Caching Layer - Cache Invalidation
 */
class BusinessHoursCacheObserver
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly VoiceRoutingCacheService $cache
    ) {}

    /**
     * Handle the model "saved" event.
     *
     * @param  BusinessHoursSchedule|BusinessHoursScheduleDay|BusinessHoursTimeRange|BusinessHoursException  $model
     */
    public function saved(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the model "updated" event.
     *
     * @param  BusinessHoursSchedule|BusinessHoursScheduleDay|BusinessHoursTimeRange|BusinessHoursException  $model
     */
    public function updated(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Handle the model "deleted" event.
     *
     * @param  BusinessHoursSchedule|BusinessHoursScheduleDay|BusinessHoursTimeRange|BusinessHoursException  $model
     */
    public function deleted(Model $model): void
    {
        $this->invalidateCache($model);
    }

    /**
     * Invalidate cache based on model type.
     *
     * @param  BusinessHoursSchedule|BusinessHoursScheduleDay|BusinessHoursTimeRange|BusinessHoursException  $model
     */
    private function invalidateCache(Model $model): void
    {
        $organizationId = match (true) {
            $model instanceof BusinessHoursSchedule => $model->organization_id,
            $model instanceof BusinessHoursScheduleDay => $this->getOrganizationIdFromScheduleDay($model),
            $model instanceof BusinessHoursTimeRange => $this->getOrganizationIdFromTimeRange($model),
            $model instanceof BusinessHoursException => $this->getOrganizationIdFromException($model),
            default => null,
        };

        if ($organizationId) {
            $this->cache->invalidateBusinessHoursSchedule($organizationId);
        }
    }

    /**
     * Get organization ID from ScheduleDay via parent schedule.
     */
    private function getOrganizationIdFromScheduleDay(BusinessHoursScheduleDay $scheduleDay): ?int
    {
        if (! $scheduleDay->relationLoaded('schedule')) {
            $scheduleDay->load('schedule');
        }

        return $scheduleDay->schedule?->organization_id;
    }

    /**
     * Get organization ID from TimeRange via parent relationships.
     */
    private function getOrganizationIdFromTimeRange(BusinessHoursTimeRange $timeRange): ?int
    {
        if (! $timeRange->relationLoaded('scheduleDay')) {
            $timeRange->load('scheduleDay.schedule');
        } elseif ($timeRange->scheduleDay && ! $timeRange->scheduleDay->relationLoaded('schedule')) {
            $timeRange->scheduleDay->load('schedule');
        }

        $scheduleDay = $timeRange->scheduleDay;

        if ($scheduleDay) {
            return $scheduleDay->schedule?->organization_id;
        }

        return null;
    }

    /**
     * Get organization ID from Exception via parent schedule.
     */
    private function getOrganizationIdFromException(BusinessHoursException $exception): ?int
    {
        if (! $exception->relationLoaded('schedule')) {
            $exception->load('schedule');
        }

        return $exception->schedule?->organization_id;
    }
}
