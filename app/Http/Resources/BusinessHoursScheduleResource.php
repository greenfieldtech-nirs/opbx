<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for Business Hours Schedule model.
 *
 * Transforms Business Hours Schedule model data into the standardized JSON response format
 * expected by the frontend.
 */
class BusinessHoursScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Build the weekly schedule structure
        $schedule = [];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        foreach ($days as $day) {
            $scheduleDay = $this->scheduleDays->firstWhere('day_of_week', $day);

            if ($scheduleDay) {
                $schedule[$day] = [
                    'enabled' => $scheduleDay->enabled,
                    'time_ranges' => $scheduleDay->timeRanges->map(function ($timeRange) {
                        return [
                            'id' => (string) $timeRange->id,
                            'start_time' => substr($timeRange->start_time, 0, 5), // HH:mm format
                            'end_time' => substr($timeRange->end_time, 0, 5),     // HH:mm format
                        ];
                    })->toArray(),
                ];
            } else {
                // Default to disabled if no schedule day exists
                $schedule[$day] = [
                    'enabled' => false,
                    'time_ranges' => [],
                ];
            }
        }

        // Build exceptions array
        $exceptions = $this->exceptions->map(function ($exception) {
            $exceptionData = [
                'id' => (string) $exception->id,
                'date' => $exception->date->format('Y-m-d'),
                'name' => $exception->name,
                'type' => $exception->type->value,
            ];

            // Only include time_ranges if type is special_hours
            if ($exception->type->value === 'special_hours') {
                $exceptionData['time_ranges'] = $exception->timeRanges->map(function ($timeRange) {
                    return [
                        'id' => (string) $timeRange->id,
                        'start_time' => substr($timeRange->start_time, 0, 5), // HH:mm format
                        'end_time' => substr($timeRange->end_time, 0, 5),     // HH:mm format
                    ];
                })->toArray();
            }

            return $exceptionData;
        })->sortBy('date')->values()->toArray();

        return [
            'id' => (string) $this->id,
            'organization_id' => (string) $this->organization_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'schedule' => $schedule,
            'exceptions' => $exceptions,
            'open_hours_action' => $this->open_hours_action,
            'closed_hours_action' => $this->closed_hours_action,
            'current_status' => $this->current_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
