<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ConferenceRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Conference Room API Resource
 *
 * Transforms conference room model data for API responses.
 *
 * @mixin ConferenceRoom
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
        // PINs are secrets: only roles that can update the room (D12) see them.
        $canSeePins = Gate::allows('update', $this->resource);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'max_participants' => $this->max_participants,
            'status' => $this->status->value,

            // Security settings
            'pin' => $this->when($canSeePins, $this->pin),
            'pin_required' => $this->pin_required,
            'host_pin' => $this->when($canSeePins, $this->host_pin),

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
