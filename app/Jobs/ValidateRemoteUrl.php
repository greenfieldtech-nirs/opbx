<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recording;
use App\Services\Recording\RecordingRemoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ValidateRemoteUrl implements ShouldQueue
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
    public function handle(RecordingRemoteService $remoteService): void
    {
        $recording = Recording::find($this->recordingId);

        if (!$recording) {
            Log::warning('ValidateRemoteUrl: Recording not found', [
                'recording_id' => $this->recordingId,
            ]);
            return;
        }

        if (!$recording->isRemote()) {
            Log::warning('ValidateRemoteUrl: Recording is not a remote URL', [
                'recording_id' => $this->recordingId,
                'type' => $recording->type,
            ]);
            return;
        }

        try {
            Log::info('ValidateRemoteUrl: Starting validation', [
                'recording_id' => $this->recordingId,
                'url' => $recording->remote_url,
            ]);

            // Get URL information
            $urlInfo = $remoteService->getUrlInfo($recording->remote_url);

            // Update recording with additional information if available
            $updates = [];

            if (isset($urlInfo['size']) && !$recording->file_size) {
                $updates['file_size'] = $urlInfo['size'];
            }

            if (isset($urlInfo['mime_type']) && !$recording->mime_type) {
                $updates['mime_type'] = $urlInfo['mime_type'];
            }

            if (!empty($updates)) {
                $recording->update($updates);
                Log::info('ValidateRemoteUrl: Updated recording with URL info', [
                    'recording_id' => $this->recordingId,
                    'updates' => $updates,
                ]);
            }

            // Log reachability status
            if ($urlInfo['reachable']) {
                Log::info('ValidateRemoteUrl: URL is reachable', [
                    'recording_id' => $this->recordingId,
                    'url' => $recording->remote_url,
                ]);
            } else {
                Log::warning('ValidateRemoteUrl: URL may not be reachable', [
                    'recording_id' => $this->recordingId,
                    'url' => $recording->remote_url,
                ]);
                // Note: We don't mark as failed since URLs can be temporarily unreachable
            }

            Log::info('ValidateRemoteUrl: Validation completed', [
                'recording_id' => $this->recordingId,
            ]);

        } catch (\Exception $e) {
            Log::error('ValidateRemoteUrl: Validation failed', [
                'recording_id' => $this->recordingId,
                'url' => $recording->remote_url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry or failure handling
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ValidateRemoteUrl job failed permanently', [
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Could send notification to admin or mark recording as failed
        // For now, just log the failure
    }
}
