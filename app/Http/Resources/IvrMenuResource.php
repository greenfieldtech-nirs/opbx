<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Recording;
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
        // Detect if audio_file_path is a recording URL and extract recording_id
        $recordingId = null;
        $audioFilePath = $this->audio_file_path;

        if ($audioFilePath && str_starts_with($audioFilePath, 'http')) {
            // Try to find a recording with this playback URL
            $recording = Recording::where('file_path', $audioFilePath)
                ->orWhere('file_url', $audioFilePath)
                ->first();

            if (! $recording) {
                // Try to match by parsing the URL
                $recording = Recording::all()->first(function ($rec) use ($audioFilePath) {
                    return str_contains($audioFilePath, $rec->file_name) ||
                           str_contains($audioFilePath, (string) $rec->id);
                });
            }

            if ($recording) {
                $recordingId = $recording->id;
            }
        }

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'audio_file_path' => $this->audio_file_path,
            'recording_id' => $recordingId,
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
