<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for recording management operations.
 *
 * Handles recording retrieval, access, and management.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
class CloudonixRecordingsClient extends CloudonixBaseClient
{
    /**
     * Get recording details by recording ID.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return array<string, mixed>|null Recording data or null on failure
     */
    public function getRecording(string $recordingId): ?array
    {
        return $this->withCircuitBreaker(
            callback: function () use ($recordingId) {
                try {
                    $response = $this->client()
                        ->get("/recordings/{$recordingId}");

                    if ($response->successful()) {
                        return $response->json();
                    }

                    Log::warning('Failed to get recording from Cloudonix', [
                        'recording_id' => $recordingId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while getting recording from Cloudonix', [
                        'recording_id' => $recordingId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: null
        );
    }

    /**
     * Get recording download URL.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return string|null Download URL or null on failure
     */
    public function getRecordingDownloadUrl(string $recordingId): ?string
    {
        $recording = $this->getRecording($recordingId);

        return $recording['downloadUrl'] ?? $recording['url'] ?? null;
    }

    /**
     * Delete a recording.
     *
     * @param  string  $recordingId  The Cloudonix recording ID
     * @return bool True on success, false on failure
     */
    public function deleteRecording(string $recordingId): bool
    {
        return $this->withCircuitBreaker(
            callback: function () use ($recordingId) {
                try {
                    $response = $this->client()
                        ->delete("/recordings/{$recordingId}");

                    if ($response->successful()) {
                        Log::info('Successfully deleted recording', [
                            'recording_id' => $recordingId,
                        ]);

                        return true;
                    }

                    Log::warning('Failed to delete recording', [
                        'recording_id' => $recordingId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return false;
                } catch (\Exception $e) {
                    Log::error('Exception while deleting recording', [
                        'recording_id' => $recordingId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: false
        );
    }

    /**
     * List recordings with optional filters.
     *
     * @param  array<string, mixed>  $filters  Optional filters (callId, from, to, etc.)
     * @return array<string, mixed>|null Array of recordings or null on failure
     */
    public function listRecordings(array $filters = []): ?array
    {
        return $this->withCircuitBreaker(
            callback: function () use ($filters) {
                try {
                    $response = $this->client()
                        ->get('/recordings', $filters);

                    if ($response->successful()) {
                        return $response->json();
                    }

                    Log::warning('Failed to list recordings from Cloudonix', [
                        'filters' => $filters,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while listing recordings from Cloudonix', [
                        'filters' => $filters,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: []
        );
    }
}
