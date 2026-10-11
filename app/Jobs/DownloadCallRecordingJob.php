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

    public int $timeout = 90;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

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
            // No redirects: the host allowlist below would not apply to the redirect target.
            $response = Http::timeout(60)->withoutRedirecting()->get($this->sourceUrl);

            if (! $response->successful()) {
                throw new \RuntimeException("Failed to download recording: HTTP {$response->status()}");
            }

            $contentType = $response->header('Content-Type') ?: 'audio/mpeg';
            $extension = match (true) {
                str_contains($contentType, 'wav') => 'wav',
                str_contains($contentType, 'mpeg'), str_contains($contentType, 'mp3') => 'mp3',
                default => 'mp3',
            };

            // Keyed by the CDR id: call_id comes from the webhook payload and could carry "../".
            $relativePath = "call-recordings/{$cdr->id}.{$extension}";
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
     * Defence in depth against SSRF: even an authenticated recordingStatus
     * webhook only ever points at Cloudonix's own domains, so refuse anything else.
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
