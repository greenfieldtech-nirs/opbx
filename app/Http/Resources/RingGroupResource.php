<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for Ring Group model.
 *
 * Transforms Ring Group model data into a standardized JSON response format.
 * Used for nested ring group data in phone number responses and other contexts.
 */
class RingGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
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
            'timeout' => $this->timeout,
            'ring_turns' => $this->ring_turns,
            'fallback_action' => $this->fallback_action->value,
            'fallback_extension_id' => $this->fallback_extension_id,
            'fallback_ring_group_id' => $this->fallback_ring_group_id,
            'fallback_ivr_menu_id' => $this->fallback_ivr_menu_id,
            'fallback_ai_assistant_id' => $this->fallback_ai_assistant_id,
            'status' => $this->status->value,
            'members' => $this->whenLoaded('members', function () {
                return $this->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'extension_id' => $member->extension_id,
                        'extension_number' => $member->extension->extension_number ?? null,
                        'user_name' => $member->extension->user->name ?? null,
                        'priority' => $member->priority,
                    ];
                });
            }),
            'members_count' => $this->when(isset($this->members_count), $this->members_count),
            'active_members_count' => $this->when(isset($this->active_members_count), $this->active_members_count),
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
