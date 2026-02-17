<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\AiAssistantLoadBalancerResource;
use App\Http\Resources\AiAssistantResource;
use App\Http\Resources\IvrMenuResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for Phone Number (DID Number) model.
 *
 * Transforms DID Number model data into a standardized JSON response format.
 * Intelligently includes related resources based on routing_type to avoid N+1 queries.
 */
class PhoneNumberResource extends JsonResource
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
            'phone_number' => $this->phone_number,
            'friendly_name' => $this->friendly_name,
            'routing_type' => $this->routing_type,
            'routing_config' => $this->routing_config ?? [],
            'status' => $this->status,
            'cloudonix_config' => $this->cloudonix_config ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Include related resources based on routing_type
            // These will only be present if manually loaded in the controller
            'extension' => $this->when(
                $this->routing_type === 'extension' && $this->extension !== null,
                fn() => new ExtensionResource($this->extension)
            ),
            'ring_group' => $this->when(
                $this->routing_type === 'ring_group' && $this->ring_group !== null,
                fn() => new RingGroupResource($this->ring_group)
            ),
            'business_hours_schedule' => $this->when(
                $this->routing_type === 'business_hours' && $this->business_hours_schedule !== null,
                fn() => new BusinessHoursScheduleResource($this->business_hours_schedule)
            ),
            'conference_room' => $this->when(
                $this->routing_type === 'conference_room' && $this->conference_room !== null,
                fn() => new ConferenceRoomResource($this->conference_room)
            ),
            'ai_assistant' => $this->when(
                $this->routing_type === 'ai_assistant' && $this->ai_assistant !== null,
                fn() => new AiAssistantResource($this->ai_assistant)
            ),
            'ai_load_balancer' => $this->when(
                $this->routing_type === 'ai_load_balancer' && $this->ai_load_balancer !== null,
                fn() => new AiAssistantLoadBalancerResource($this->ai_load_balancer)
            ),
            'ivr_menu' => $this->when(
                $this->routing_type === 'ivr_menu' && $this->ivr_menu !== null,
                fn() => new IvrMenuResource($this->ivr_menu)
            ),
        ];
    }
}
