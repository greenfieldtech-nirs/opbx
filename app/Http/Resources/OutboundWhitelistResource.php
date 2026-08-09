<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\WhitelistStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for OutboundWhitelist model.
 *
 * Transforms OutboundWhitelist model data into a standardized JSON response format.
 */
class OutboundWhitelistResource extends JsonResource
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
            'destination_country' => $this->destination_country,
            'destination_prefix' => $this->destination_prefix,
            'outbound_trunk_name' => $this->outbound_trunk_name,
            'default_caller_id_did_id' => $this->default_caller_id_did_id,
            'default_caller_id' => $this->whenLoaded('defaultCallerId', fn () => $this->defaultCallerId ? [
                'id' => $this->defaultCallerId->id,
                'phone_number' => $this->defaultCallerId->phone_number,
                'friendly_name' => $this->defaultCallerId->friendly_name,
            ] : null),
            'status' => $this->status?->value ?? WhitelistStatus::ACTIVE->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
