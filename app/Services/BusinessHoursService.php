<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BusinessHoursSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Business Hours Service
 *
 * Handles business logic for business hours schedules.
 */
class BusinessHoursService
{
    /**
     * Create a new business hours schedule.
     *
     * @param  array  $data  Schedule data
     * @param  int  $organizationId  Organization ID
     */
    public function createSchedule(array $data, int $organizationId): BusinessHoursSchedule
    {
        return DB::transaction(function () use ($data, $organizationId) {
            $schedule = BusinessHoursSchedule::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'organization_id' => $organizationId,
                'open_hours_action' => $data['open_hours_action'] ?? null,
                'open_hours_action_type' => $data['open_hours_action_type'] ?? null,
                'closed_hours_action' => $data['closed_hours_action'] ?? null,
                'closed_hours_action_type' => $data['closed_hours_action_type'] ?? null,
            ]);

            $this->createScheduleDays($schedule, $data['schedule'] ?? []);
            $this->createExceptions($schedule, $data['exceptions'] ?? []);

            return $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);
        });
    }

    /**
     * Update an existing business hours schedule.
     *
     * @param  BusinessHoursSchedule  $schedule  The schedule to update
     * @param  array  $data  Updated data
     */
    public function updateSchedule(BusinessHoursSchedule $schedule, array $data): BusinessHoursSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            // Update basic fields
            $schedule->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'open_hours_action' => $data['open_hours_action'] ?? null,
                'open_hours_action_type' => $data['open_hours_action_type'] ?? null,
                'closed_hours_action' => $data['closed_hours_action'] ?? null,
                'closed_hours_action_type' => $data['closed_hours_action_type'] ?? null,
            ]);

            // Update schedule days
            $schedule->scheduleDays()->delete();
            $this->createScheduleDays($schedule, $data['schedule'] ?? []);

            // Update exceptions
            $schedule->exceptions()->delete();
            $this->createExceptions($schedule, $data['exceptions'] ?? []);

            return $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);
        });
    }

    /**
     * Delete a business hours schedule.
     *
     * @param  BusinessHoursSchedule  $schedule  The schedule to delete
     */
    public function deleteSchedule(BusinessHoursSchedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            $schedule->scheduleDays()->delete();
            $schedule->exceptions()->delete();

            return $schedule->delete();
        });
    }

    /**
     * Duplicate a business hours schedule.
     *
     * @param  BusinessHoursSchedule  $schedule  The schedule to duplicate
     * @param  string  $newName  Name for the duplicate
     * @param  int  $newOrganizationId  Organization ID for the duplicate
     */
    public function duplicateSchedule(
        BusinessHoursSchedule $schedule,
        string $newName,
        int $newOrganizationId
    ): BusinessHoursSchedule {
        return DB::transaction(function () use ($schedule, $newName, $newOrganizationId) {
            // Load relationships
            $schedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);

            // Create duplicate
            $newSchedule = BusinessHoursSchedule::create([
                'name' => $newName,
                'description' => $schedule->description,
                'status' => 'inactive',
                'organization_id' => $newOrganizationId,
                'open_hours_action' => $schedule->open_hours_action,
                'open_hours_action_type' => $schedule->open_hours_action_type,
                'closed_hours_action' => $schedule->closed_hours_action,
                'closed_hours_action_type' => $schedule->closed_hours_action_type,
            ]);

            // Copy schedule days
            foreach ($schedule->scheduleDays as $day) {
                $newDay = $newSchedule->scheduleDays()->create([
                    'day_of_week' => $day->day_of_week,
                    'enabled' => $day->enabled,
                ]);

                foreach ($day->timeRanges as $timeRange) {
                    $newDay->timeRanges()->create([
                        'start_time' => $timeRange->start_time,
                        'end_time' => $timeRange->end_time,
                    ]);
                }
            }

            // Copy exceptions
            foreach ($schedule->exceptions as $exception) {
                $newException = $newSchedule->exceptions()->create([
                    'name' => $exception->name,
                    'date' => $exception->date,
                    'start_time' => $exception->start_time,
                    'end_time' => $exception->end_time,
                    'action' => $exception->action,
                    'action_type' => $exception->action_type,
                    'action_id' => $exception->action_id,
                ]);

                foreach ($exception->timeRanges as $timeRange) {
                    $newException->timeRanges()->create([
                        'start_time' => $timeRange->start_time,
                        'end_time' => $timeRange->end_time,
                    ]);
                }
            }

            return $newSchedule->load(['scheduleDays.timeRanges', 'exceptions.timeRanges']);
        });
    }

    /**
     * Create schedule days and time ranges from data.
     */
    protected function createScheduleDays(BusinessHoursSchedule $schedule, array $scheduleData): void
    {
        foreach ($scheduleData as $dayData) {
            $day = $schedule->scheduleDays()->create([
                'day_of_week' => $dayData['day_of_week'],
                'enabled' => $dayData['enabled'],
            ]);

            foreach ($dayData['time_ranges'] ?? [] as $timeRange) {
                $day->timeRanges()->create([
                    'start_time' => $timeRange['start_time'],
                    'end_time' => $timeRange['end_time'],
                ]);
            }
        }
    }

    /**
     * Create exceptions from data.
     */
    protected function createExceptions(BusinessHoursSchedule $schedule, array $exceptions): void
    {
        foreach ($exceptions as $exceptionData) {
            $exception = $schedule->exceptions()->create([
                'name' => $exceptionData['name'],
                'date' => $exceptionData['date'],
                'start_time' => $exceptionData['start_time'] ?? null,
                'end_time' => $exceptionData['end_time'] ?? null,
                'action' => $exceptionData['action'] ?? null,
                'action_type' => $exceptionData['action_type'] ?? null,
                'action_id' => $exceptionData['action_id'] ?? null,
            ]);

            foreach ($exceptionData['time_ranges'] ?? [] as $timeRange) {
                $exception->timeRanges()->create([
                    'start_time' => $timeRange['start_time'],
                    'end_time' => $timeRange['end_time'],
                ]);
            }
        }
    }
}
