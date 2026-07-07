<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Tracking Session API Resource.
 *
 * @mixin CallTrackingSession
 */
class CallTrackingSessionResource extends JsonResource
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
            'call_tracking_number_id' => $this->call_tracking_number_id,
            'did_number_id' => $this->did_number_id,
            'call_id' => $this->call_id,
            'session_id' => $this->session_id,
            'caller_number' => $this->caller_number,
            'caller_country' => $this->caller_country,
            'called_number' => $this->called_number,
            'source' => $this->source,
            'medium' => $this->medium,
            'campaign_name' => $this->campaign_name,
            'disposition' => $this->disposition,
            'duration' => $this->duration,
            'billsec' => $this->billsec,
            'is_answered' => $this->is_answered,
            'is_converted' => $this->is_converted,
            'conversion_value' => $this->conversion_value,
            'started_at' => $this->started_at?->toISOString(),
            'answered_at' => $this->answered_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'campaign' => $this->whenLoaded('campaign', fn () => [
                'id' => $this->campaign->id,
                'name' => $this->campaign->name,
            ]),
            'did' => $this->whenLoaded('did', fn () => [
                'id' => $this->did->id,
                'phone_number' => $this->did->phone_number,
            ]),
        ];
    }
}
