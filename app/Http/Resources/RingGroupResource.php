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
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
