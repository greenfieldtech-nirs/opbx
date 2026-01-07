<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'recordings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'file_path',
        'remote_url',
        'original_filename',
        'file_size',
        'mime_type',
        'duration_seconds',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'duration_seconds' => 'integer',
            'status' => 'string',
            'type' => 'string',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope());
    }

    /**
     * Get the organization that owns the recording.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who created the recording.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the recording.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Check if the recording is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the recording is an uploaded file.
     */
    public function isUploaded(): bool
    {
        return $this->type === 'upload';
    }

    /**
     * Check if the recording is a remote URL.
     */
    public function isRemote(): bool
    {
        return $this->type === 'remote';
    }

    /**
     * Get the public URL for the recording.
     *
     * @deprecated Use getPlaybackUrl() or getDownloadUrl() instead
     */
    public function getPublicUrl(): string
    {
        if ($this->isRemote()) {
            return $this->remote_url;
        }

        if ($this->isUploaded()) {
            return asset('storage/recordings/' . $this->organization_id . '/' . $this->file_path);
        }

        return '';
    }

    /**
     * Get the playback URL for the recording.
     * For uploaded files, returns token-based URL to secure download endpoint.
     * For remote files, returns the remote URL directly.
     *
     * @param int $userId The user ID to generate the token for
     */
    public function getPlaybackUrl(int $userId): string
    {
        if ($this->isRemote()) {
            return $this->remote_url;
        }

        if ($this->isUploaded()) {
            // Use the same token-based approach as download
            $accessService = app(\App\Services\Recording\RecordingAccessService::class);
            $token = $accessService->generateAccessToken($this, $userId);

            return route('recordings.secure-download') . '?token=' . urlencode($token);
        }

        return '';
    }

    /**
     * Get the download URL for the recording.
     * For uploaded files, generates a token-based URL to the secure download endpoint.
     *
     * @param int $userId The user ID to generate the token for
     */
    public function getDownloadUrl(int $userId): string
    {
        if ($this->isUploaded()) {
            // Import the access service to generate a token
            $accessService = app(\App\Services\Recording\RecordingAccessService::class);
            $token = $accessService->generateAccessToken($this, $userId);

            return route('recordings.secure-download') . '?token=' . urlencode($token);
        }

        return '';
    }

    /**
     * Generate a temporary signed URL for MinIO storage.
     */
    private function generateTemporaryUrl(int $minutes): string
    {
        $filePath = "{$this->organization_id}/{$this->file_path}";

        return \Illuminate\Support\Facades\Storage::disk('recordings')->temporaryUrl(
            $filePath,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Get the formatted file size.
     */
    public function getFormattedFileSize(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->file_size;
        $i = 0;

        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get the formatted duration.
     */
    public function getFormattedDuration(): string
    {
        if (!$this->duration_seconds) {
            return 'Unknown';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
