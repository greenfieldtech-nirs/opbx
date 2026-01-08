<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordingResource extends JsonResource
{
    /**
     * The user ID for generating access tokens
     */
    protected static ?int $currentUserId = null;

    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @return void
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    /**
     * Set the current user ID for all resources in this request.
     *
     * @param  int  $userId
     * @return void
     */
    public static function setCurrentUserId(int $userId): void
    {
        static::$currentUserId = $userId;
    }



    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
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
            'public_url' => $this->getPublicUrl(), // Kept for backward compatibility
            'created_by' => $this->creator?->name ?? 'Unknown',
            'updated_by' => $this->updater?->name ?? 'Unknown',
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];

        // Add playback URL if user ID is available
        if (static::$currentUserId) {
            $data['playback_url'] = $this->getPlaybackUrl(static::$currentUserId);
        }

        return $data;
    }
}
