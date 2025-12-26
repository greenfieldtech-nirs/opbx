<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Conference Room API Resource
 *
 * Transforms conference room model data for API responses.
 *
 * @mixin \App\Models\ConferenceRoom
 */
class ConferenceRoomResource extends JsonResource
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
            'max_participants' => $this->max_participants,
            'status' => $this->status->value,

            // Security settings
            'pin' => $this->pin,
            'pin_required' => $this->pin_required,
            'host_pin' => $this->host_pin,

            // Recording settings
            'recording_enabled' => $this->recording_enabled,
            'recording_auto_start' => $this->recording_auto_start,
            'recording_webhook_url' => $this->recording_webhook_url,

            // Participant settings
            'wait_for_host' => $this->wait_for_host,
            'mute_on_entry' => $this->mute_on_entry,

            // Audio settings
            'announce_join_leave' => $this->announce_join_leave,
            'music_on_hold' => $this->music_on_hold,

            // Talk detection settings
            'talk_detection_enabled' => $this->talk_detection_enabled,
            'talk_detection_webhook_url' => $this->talk_detection_webhook_url,

            // Timestamps
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
