<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Models\AutoDialerCampaign;
use Carbon\Carbon;

/**
 * Dialing Scheduler Service
 *
 * Validates campaign scheduling constraints.
 */
class DialingScheduler
{
    /**
     * Calendar-date check in the campaign timezone. start_date/end_date are dates (midnight), so
     * comparing instants would exclude the whole end date.
     */
    public function isWithinDateRange(AutoDialerCampaign $campaign): bool
    {
        $today = now($campaign->timezone)->format('Y-m-d');

        return $today >= $campaign->start_date->format('Y-m-d') && $today <= $campaign->end_date->format('Y-m-d');
    }

    /**
     * Check if campaign is within its scheduled hours.
     */
    public function isWithinSchedule(AutoDialerCampaign $campaign): bool
    {
        $now = now($campaign->timezone);

        if (! $this->isWithinDateRange($campaign)) {
            return false;
        }

        // Check day of week
        $currentDay = strtolower($now->format('l'));
        if (! in_array($currentDay, $campaign->days_active ?? [], true)) {
            return false;
        }

        // Check time range
        $currentHour = (int) $now->format('G');
        if ($currentHour < $campaign->start_time || $currentHour >= $campaign->end_time) {
            return false;
        }

        return true;
    }

    /**
     * Get the next scheduled run time for a campaign.
     */
    public function getNextScheduledTime(AutoDialerCampaign $campaign): ?Carbon
    {
        $now = now($campaign->timezone);
        $today = $now->copy()->startOfDay();

        // Check if we're still within the date range (the end date itself is a valid day)
        if ($now->format('Y-m-d') > $campaign->end_date->format('Y-m-d')) {
            return null; // Campaign has ended
        }

        // Check today
        $currentHour = (int) $now->format('G');
        if ($this->isActiveDay($campaign, $today) && $currentHour < $campaign->end_time) {
            $nextTime = $today->copy()->setTime($campaign->start_time, 0);

            if ($nextTime->gt($now)) {
                return $nextTime;
            }

            // Already started today, return now if within hours
            if ($currentHour >= $campaign->start_time) {
                return $now;
            }
        }

        // Find next active day
        for ($i = 1; $i <= 7; $i++) {
            $nextDay = $today->copy()->addDays($i);

            // Check if we've passed the end date
            if ($nextDay->gt($campaign->end_date)) {
                return null;
            }

            if ($this->isActiveDay($campaign, $nextDay)) {
                return $nextDay->setTime($campaign->start_time, 0);
            }
        }

        return null;
    }

    /**
     * Check if a specific day is active for the campaign.
     */
    private function isActiveDay(AutoDialerCampaign $campaign, Carbon $date): bool
    {
        $day = strtolower($date->format('l'));

        return in_array($day, $campaign->days_active ?? [], true);
    }
}
