<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallDetailRecord;
use App\Scopes\OrganizationScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a call recording from the URL Cloudonix provided in its
 * recordingStatusCallback and stores it on the "recordings" MinIO disk,
 * under the same `{organization_id}/...` convention RecordingUploadService
 * uses for uploaded prompt files.
 */
class DownloadCallRecordingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public function __construct(
        private readonly int $callDetailRecordId,
        private readonly string $sourceUrl
    ) {}

    public function handle(): void
    {
        $cdr = CallDetailRecord::withoutGlobalScope(OrganizationScope::class)->find($this->callDetailRecordId);

        if (! $cdr) {
            Log::warning('DownloadCallRecordingJob: CDR not found', [
                'call_detail_record_id' => $this->callDetailRecordId,
            ]);

            return;
        }

        Log::info('DownloadCallRecordingJob: Starting download', [
            'call_detail_record_id' => $this->callDetailRecordId,
            'source_url' => $this->sourceUrl,
        ]);

        if (! $this->isAllowedRecordingHost($this->sourceUrl)) {
            Log::error('DownloadCallRecordingJob: Refusing to fetch recording from disallowed host', [
                'call_detail_record_id' => $this->callDetailRecordId,
                'source_url' => $this->sourceUrl,
            ]);

            $cdr->update(['recording_status' => 'failed']);

            return;
        }

        try {
            $response = Http::timeout(60)->get($this->sourceUrl);

            if (! $response->successful()) {
                throw new \RuntimeException("Failed to download recording: HTTP {$response->status()}");
            }

            $contentType = $response->header('Content-Type') ?: 'audio/mpeg';
            $extension = match (true) {
                str_contains($contentType, 'wav') => 'wav',
                str_contains($contentType, 'mpeg'), str_contains($contentType, 'mp3') => 'mp3',
                default => 'mp3',
            };

            $relativePath = "call-recordings/{$cdr->call_id}.{$extension}";
            $storagePath = "{$cdr->organization_id}/{$relativePath}";

            Storage::disk('recordings')->put($storagePath, $response->body());

            $cdr->update([
                'recording_status' => 'available',
                'recording_stored_path' => $relativePath,
                'recording_mime_type' => $contentType,
            ]);

            Log::info('DownloadCallRecordingJob: Recording stored successfully', [
                'call_detail_record_id' => $this->callDetailRecordId,
                'storage_path' => $storagePath,
            ]);
        } catch (\Exception $e) {
            Log::error('DownloadCallRecordingJob: Download failed', [
                'call_detail_record_id' => $this->callDetailRecordId,
                'source_url' => $this->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            $cdr->update(['recording_status' => 'failed']);

            throw $e;
        }
    }

    /**
     * The recordingStatusCallback webhook that supplies $this->sourceUrl has no
     * strong authentication (see CloudonixWebhookController::recordingStatus()),
     * so this job must not blindly fetch whatever URL it's given - that would
     * let anyone who can reach the webhook make this server issue arbitrary
     * outbound requests (SSRF). Only fetch from Cloudonix's own domains.
     */
    private function isAllowedRecordingHost(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        return $host === 'cloudonix.io'
            || str_ends_with($host, '.cloudonix.io')
            || $host === 'cloudonix.net'
            || str_ends_with($host, '.cloudonix.net');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DownloadCallRecordingJob: Job failed permanently', [
            'call_detail_record_id' => $this->callDetailRecordId,
            'error' => $exception->getMessage(),
        ]);

        CallDetailRecord::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $this->callDetailRecordId)
            ->update(['recording_status' => 'failed']);
    }
}
