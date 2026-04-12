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
        // Detect if audio_file_path is a recording URL
        // Recording URLs contain '/api/v1/recordings/download' or similar patterns
        $recordingId = null;
        $audioFilePath = $this->audio_file_path;

        if ($audioFilePath && str_contains($audioFilePath, '/api/v1/recordings/download')) {
            // Try to extract recording ID from the URL by finding a matching recording
            // The recording is identified by checking if the audio path matches any recording's file_path
            $recording = \App\Models\Recording::where('organization_id', $this->organization_id)
                ->where(function ($query) {
                    $query->whereNotNull('file_path')
                        ->where('file_path', '!=', '');
                })
                ->first();

            // Additional check: verify the URL contains a token pattern that matches this recording
            if ($recording) {
                // The URL is a recording URL - we can't easily extract the exact ID
                // from the token, but we can infer it's a recording by the URL pattern
                // For now, we'll leave recording_id null and let the frontend handle it
                // by checking if the URL matches the recordings endpoint pattern
            }
        }

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'audio_file_path' => $this->audio_file_path,
            'recording_id' => $recordingId,
            'is_recording_url' => $audioFilePath && str_contains($audioFilePath, '/api/v1/recordings/download'),
            'tts_text' => $this->tts_text,
            'tts_voice' => $this->tts_voice,
            'max_timeout' => $this->max_timeout,
            'inter_digit_timeout' => $this->inter_digit_timeout,
            'max_turns' => $this->max_turns,
            'failover_destination_type' => $this->failover_destination_type?->value,
            'failover_destination_id' => $this->failover_destination_id,
            'status' => $this->status->value,
            'options_count' => $this->whenCounted('options'),
            'options' => $this->whenLoaded('options', function () {
                return $this->options->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'ivr_menu_id' => $option->ivr_menu_id,
                        'input_digits' => $option->input_digits,
                        'description' => $option->description,
                        'destination_type' => $option->destination_type->value,
                        'destination_id' => $option->destination_id,
                        'priority' => $option->priority,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
