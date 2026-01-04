<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recording;
use App\Services\Recording\RecordingUploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessRecordingUpload implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $recordingId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(RecordingUploadService $uploadService): void
    {
        $recording = Recording::find($this->recordingId);

        if (!$recording) {
            Log::warning('ProcessRecordingUpload: Recording not found', [
                'recording_id' => $this->recordingId,
            ]);
            return;
        }

        if (!$recording->isUploaded()) {
            Log::warning('ProcessRecordingUpload: Recording is not an upload', [
                'recording_id' => $this->recordingId,
                'type' => $recording->type,
            ]);
            return;
        }

        try {
            Log::info('ProcessRecordingUpload: Starting processing', [
                'recording_id' => $this->recordingId,
                'filename' => $recording->file_path,
            ]);

            // Get the full file path
            $filePath = storage_path("app/recordings/{$recording->organization_id}/{$recording->file_path}");

            if (!file_exists($filePath)) {
                Log::error('ProcessRecordingUpload: File not found', [
                    'recording_id' => $this->recordingId,
                    'file_path' => $filePath,
                ]);
                return;
            }

            // Extract additional metadata if needed
            // Since FFMPEG is not available, we'll use basic file operations
            $fileSize = filesize($filePath);

            // Update file size if it wasn't captured during upload
            if ($recording->file_size !== $fileSize) {
                $recording->update(['file_size' => $fileSize]);
                Log::info('ProcessRecordingUpload: Updated file size', [
                    'recording_id' => $this->recordingId,
                    'old_size' => $recording->file_size,
                    'new_size' => $fileSize,
                ]);
            }

            // For WAV files, try to extract duration from header
            if ($recording->mime_type === 'audio/wav') {
                $duration = $this->extractWavDuration($filePath);
                if ($duration > 0) {
                    $recording->update(['duration_seconds' => $duration]);
                    Log::info('ProcessRecordingUpload: Extracted WAV duration', [
                        'recording_id' => $this->recordingId,
                        'duration_seconds' => $duration,
                    ]);
                }
            }

            Log::info('ProcessRecordingUpload: Processing completed', [
                'recording_id' => $this->recordingId,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessRecordingUpload: Processing failed', [
                'recording_id' => $this->recordingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry or failure handling
        }
    }

    /**
     * Extract duration from WAV file header.
     */
    private function extractWavDuration(string $filePath): int
    {
        try {
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                return 0;
            }

            // Read WAV header (44 bytes minimum)
            $header = fread($handle, 44);
            if (strlen($header) < 24) {
                fclose($handle);
                return 0;
            }

            // Extract sample rate (bytes 24-27, little endian)
            $sampleRate = unpack('V', substr($header, 24, 4))[1] ?? 0;

            // Extract data size (bytes 40-43, little endian)
            $dataSize = unpack('V', substr($header, 40, 4))[1] ?? 0;

            fclose($handle);

            if ($sampleRate > 0 && $dataSize > 0) {
                // Calculate duration (rough estimate for uncompressed WAV)
                // Duration = data_size / (sample_rate * channels * bytes_per_sample)
                // Assuming 16-bit stereo (most common)
                $bytesPerSecond = $sampleRate * 2 * 2; // 2 channels, 2 bytes per sample
                $duration = $dataSize / $bytesPerSecond;

                return (int) round($duration);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to extract WAV duration', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessRecordingUpload job failed permanently', [
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Could send notification to admin or mark recording as failed
        // For now, just log the failure
    }
}
