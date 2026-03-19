<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutoDialerCampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'auto_start' => $this->auto_start,

            // Routing
            'routing_destination_type' => $this->routing_destination_type?->value,
            'routing_destination_label' => $this->routing_destination_type?->label(),
            'routing_destination_id' => $this->routing_destination_id,

            // Settings
            'dial_timeout' => $this->dial_timeout,
            'destination_connect' => $this->destination_connect,
            'caller_id' => $this->caller_id,
            'max_dial_attempts' => $this->max_dial_attempts,
            'calls_per_second' => $this->calls_per_second,

            // Schedule
            'days_active' => $this->days_active,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'timezone' => $this->timezone,
            'schedule' => $this->schedule, // New full schedule field

            // Recording, Time Limit & AMD
            'time_limit' => $this->time_limit,
            'record_calls' => $this->record_calls,
            'amd_enabled' => $this->amd_enabled,
            'amd_mode' => $this->amd_mode?->value,
            'amd_timeout' => $this->amd_timeout,
            'amd_speech_threshold' => $this->amd_speech_threshold,
            'amd_speech_end_threshold' => $this->amd_speech_end_threshold,
            'amd_silence_timeout' => $this->amd_silence_timeout,

            // Statistics
            'statistics' => [
                'total_destinations' => $this->total_destinations,
                'completed_calls' => $this->completed_calls,
                'failed_calls' => $this->failed_calls,
                'pending_calls' => $this->pending_calls,
                'progress_percentage' => $this->getProgressPercentage(),
            ],

            // Timestamps
            'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            // Computed
            'is_runnable' => $this->isRunnable(),
            'has_list' => $this->hasList(),
        ];
    }
}
