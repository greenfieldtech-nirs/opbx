<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for AI Assistant Load Balancer model.
 *
 * Transforms AiAssistantLoadBalancer model data into a standardized JSON response format.
 */
class AiAssistantLoadBalancerResource extends JsonResource
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
            'status' => $this->status->value,
            'fallback_action' => $this->fallback_action->value,
            'fallback_extension_id' => $this->fallback_extension_id,
            'fallback_ring_group_id' => $this->fallback_ring_group_id,
            'fallback_ivr_menu_id' => $this->fallback_ivr_menu_id,
            'fallback_ai_assistant_id' => $this->fallback_ai_assistant_id,
            'members' => $this->whenLoaded('members', function () {
                return $this->members->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'ai_assistant_id' => $member->ai_assistant_id,
                        'ai_assistant_name' => $member->aiAssistant->name ?? null,
                        'priority' => $member->priority,
                        'weight' => $member->weight,
                        'position' => $member->position,
                        'status' => $member->status,
                    ];
                });
            }),
            'members_count' => $this->when(isset($this->members_count), $this->members_count),
            'active_members_count' => $this->when(isset($this->active_members_count), $this->active_members_count),
            'fallback_extension' => $this->whenLoaded('fallbackExtension', function () {
                return $this->fallbackExtension ? [
                    'id' => $this->fallbackExtension->id,
                    'extension_number' => $this->fallbackExtension->extension_number,
                ] : null;
            }),
            'fallback_ring_group' => $this->whenLoaded('fallbackRingGroup', function () {
                return $this->fallbackRingGroup ? [
                    'id' => $this->fallbackRingGroup->id,
                    'name' => $this->fallbackRingGroup->name,
                ] : null;
            }),
            'fallback_ivr_menu' => $this->whenLoaded('fallbackIvrMenu', function () {
                return $this->fallbackIvrMenu ? [
                    'id' => $this->fallbackIvrMenu->id,
                    'name' => $this->fallbackIvrMenu->name,
                ] : null;
            }),
            'fallback_ai_assistant' => $this->whenLoaded('fallbackAiAssistant', function () {
                return $this->fallbackAiAssistant ? [
                    'id' => $this->fallbackAiAssistant->id,
                    'name' => $this->fallbackAiAssistant->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
