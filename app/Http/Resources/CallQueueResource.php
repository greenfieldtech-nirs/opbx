<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for CallQueue model.
 */
class CallQueueResource extends JsonResource
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
            'strategy' => $this->strategy->value,
            'agent_ring_timeout' => $this->agent_ring_timeout,
            'max_wait_seconds' => $this->max_wait_seconds,
            'wrap_up_seconds' => $this->wrap_up_seconds,
            'moh_recording_id' => $this->moh_recording_id,
            'fallback_action' => $this->fallback_action->value,
            'fallback_extension_id' => $this->fallback_extension_id,
            'fallback_ring_group_id' => $this->fallback_ring_group_id,
            'fallback_ivr_menu_id' => $this->fallback_ivr_menu_id,
            'fallback_ai_assistant_id' => $this->fallback_ai_assistant_id,
            'fallback_ai_load_balancer_id' => $this->fallback_ai_load_balancer_id,
            'status' => $this->status->value,
            'agents' => $this->whenLoaded('agents', function () {
                return $this->agents->map(function ($agent) {
                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'extension_id' => $agent->extension?->id,
                        'extension_number' => $agent->extension?->extension_number,
                    ];
                });
            }),
            'agents_count' => $this->when(isset($this->agents_count), $this->agents_count),
            'moh_recording' => $this->whenLoaded('mohRecording', function () {
                return [
                    'id' => $this->mohRecording->id,
                    'name' => $this->mohRecording->name,
                ];
            }),
            'fallback_extension' => $this->whenLoaded('fallbackExtension', function () {
                return [
                    'id' => $this->fallbackExtension->id,
                    'extension_number' => $this->fallbackExtension->extension_number,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
