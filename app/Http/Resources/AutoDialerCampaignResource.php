<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AutoDialerCampaign;
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
            'organization_id' => $this->organization_id,
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

            // Concurrent Active Calls (CAC) Configuration
            // Replaces the old calls_per_second (CPS) model
            'concurrent_active_calls' => $this->concurrent_active_calls,
            'calls_per_second' => $this->calls_per_second ?? 1,
            'max_cac' => AutoDialerCampaign::MAX_CAC,
            'max_cps' => AutoDialerCampaign::MAX_CPS,
            'api_interval_ms' => $this->getApiIntervalMilliseconds(), // 1000 / CPS

            // Schedule
            'days_active' => $this->days_active,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'timezone' => $this->timezone,
            'schedule' => $this->schedule, // New full schedule field

            // Recording, Time Limit & AMD Actions
            'time_limit' => $this->time_limit,
            'record_calls' => $this->record_calls,
            'action_voicemail' => $this->action_voicemail,
            'action_human' => $this->action_human,
            'action_unknown' => $this->action_unknown,
            'retry_on_voicemail' => $this->retry_on_voicemail,

            // Statistics
            'statistics' => [
                'total_destinations' => $this->total_destinations,
                'completed_calls' => $this->completed_calls,
                'failed_calls' => $this->failed_calls,
                'voicemail_calls' => $this->voicemail_calls,
                'pending_calls' => $this->pending_calls,
                'progress_percentage' => $this->getProgressPercentage(),
            ],

            // Timestamps
            'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),

            // Cloudonix credentials from organization settings
            'cloudonix_api_key' => $this->organization->cloudonixSettings?->domain_api_key,
            'cloudonix_domain' => $this->organization->cloudonixSettings?->domain_uuid ?? $this->organization->cloudonixSettings?->domain_name,
            'cloudonix_api_url' => config('services.cloudonix.api_url', 'https://api.cloudonix.io'),

            // Caller ID Pooling
            'caller_id_pool_enabled' => $this->caller_id_pool_enabled,
            'caller_id_strategy' => $this->caller_id_strategy?->value,
            'caller_id_strategy_label' => $this->caller_id_strategy?->label(),
            'caller_id_pool' => $this->whenLoaded('callerIds', function () {
                return $this->callerIds->map(fn ($did) => [
                    'did_id' => $did->id,
                    'phone_number' => $did->phone_number,
                    'friendly_name' => $did->friendly_name,
                    'weight' => $did->pivot->weight ?? 1,
                ]);
            }, []),
            'caller_id_stats' => $this->whenLoaded('callerIdStats', function () {
                $stats = $this->callerIdStats;
                $totalCalls = $stats->sum('total_calls');

                return [
                    'total_calls' => $totalCalls,
                    'by_did' => $stats->map(fn ($stat) => [
                        'did_id' => $stat->did_number_id,
                        'phone_number' => $stat->didNumber?->phone_number,
                        'friendly_name' => $stat->didNumber?->friendly_name,
                        'total_calls' => $stat->total_calls,
                        'completed' => $stat->completed_calls,
                        'failed' => $stat->failed_calls,
                        'success_rate' => $stat->success_rate,
                    ]),
                ];
            }),

            // Computed
            'is_runnable' => $this->isRunnable(),
            'has_list' => $this->hasList(),
            'lists_count' => $this->whenLoaded('lists', fn () => $this->lists->count(), 0),
        ];
    }
}
