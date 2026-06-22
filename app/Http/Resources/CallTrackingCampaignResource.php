<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Tracking Campaign API Resource.
 *
 * @mixin CallTrackingCampaign
 */
class CallTrackingCampaignResource extends JsonResource
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
            'source' => $this->source,
            'medium' => $this->medium,
            'description' => $this->description,
            'status' => $this->status->value,
            'destination_type' => $this->destination_type->value,
            'destination_config' => $this->destination_config,
            'conversion_rule' => $this->conversion_rule,
            'tracking_numbers_count' => $this->whenCounted('tracking_numbers', fn () => $this->tracking_numbers_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
