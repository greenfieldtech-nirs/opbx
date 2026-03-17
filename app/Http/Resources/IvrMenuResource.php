<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for IVR Menu model.
 *
 * Transforms IVR Menu model data into a standardized JSON response format.
 */
class IvrMenuResource extends JsonResource
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
            'audio_file_path' => $this->audio_file_path,
            'tts_text' => $this->tts_text,
            'tts_voice' => $this->tts_voice,
            'max_timeout' => $this->max_timeout,
            'inter_digit_timeout' => $this->inter_digit_timeout,
            'max_turns' => $this->max_turns,
            'failover_destination_type' => $this->failover_destination_type?->value,
            'failover_destination_id' => $this->failover_destination_id,
            'status' => $this->status->value,
            'options_count' => $this->whenCounted('options'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
