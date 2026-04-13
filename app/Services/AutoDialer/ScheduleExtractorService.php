<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

/**
 * Schedule Extractor Service
 *
 * Extracts days_active and time ranges from campaign schedule data.
 */
class ScheduleExtractorService
{
    /**
     * Extract days_active array from schedule data.
     *
     * @param  array<string, mixed>  $schedule  The schedule configuration
     * @return array<int, string> Array of active day names (lowercase)
     */
    public function extractDaysActive(array $schedule): array
    {
        $daysActive = [];

        foreach ($schedule as $day => $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                $daysActive[] = strtolower($day);
            }
        }

        return $daysActive;
    }

    /**
     * Extract start_time and end_time from first enabled day's first time range.
     *
     * @param  array<string, mixed>  $schedule  The schedule configuration
     * @return array<string, int> Array with start_time and end_time (hours as integers)
     */
    public function extractTimeRange(array $schedule): array
    {
        foreach ($schedule as $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                $timeRanges = $config['time_ranges'] ?? [];
                if (! empty($timeRanges) && is_array($timeRanges[0])) {
                    $startTime = $timeRanges[0]['start_time'] ?? '09:00';
                    $endTime = $timeRanges[0]['end_time'] ?? '17:00';

                    return [
                        'start_time' => (int) substr($startTime, 0, 2),
                        'end_time' => (int) substr($endTime, 0, 2),
                    ];
                }
            }
        }

        return ['start_time' => 9, 'end_time' => 17];
    }

    /**
     * Process schedule data to extract all legacy fields.
     *
     * @param  array<string, mixed>  $schedule  The schedule configuration
     * @return array<string, mixed> Array with days_active, start_time, end_time
     */
    public function processSchedule(array $schedule): array
    {
        $timeRange = $this->extractTimeRange($schedule);

        return [
            'days_active' => $this->extractDaysActive($schedule),
            'start_time' => $timeRange['start_time'],
            'end_time' => $timeRange['end_time'],
        ];
    }
}
