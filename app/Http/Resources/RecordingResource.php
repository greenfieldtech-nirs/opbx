<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordingResource extends JsonResource
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
            'type' => $this->type,
            'file_path' => $this->file_path,
            'remote_url' => $this->remote_url,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->getFormattedFileSize(),
            'mime_type' => $this->mime_type,
            'duration_seconds' => $this->duration_seconds,
            'formatted_duration' => $this->getFormattedDuration(),
            'status' => $this->status,
            'public_url' => $this->getPublicUrl(),
            'created_by' => $this->creator?->name ?? 'Unknown',
            'updated_by' => $this->updater?->name ?? 'Unknown',
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
