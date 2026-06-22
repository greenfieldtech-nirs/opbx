<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Tracking Number API Resource.
 *
 * @mixin CallTrackingNumber
 */
class CallTrackingNumberResource extends JsonResource
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
            'call_tracking_campaign_id' => $this->call_tracking_campaign_id,
            'did_number_id' => $this->did_number_id,
            'phone_number' => $this->whenLoaded('did', fn () => $this->did->phone_number),
            'friendly_name' => $this->friendly_name,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
