<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\Recording;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Splits queue music-on-hold recordings into ~10s chunks so the queue hold
 * poll loop stays responsive (WP1, Scenario 2). The ordered segment list is
 * cached in Redis; absence of the key means "unsegmented" and callers must
 * fall back to playing the full MOH file.
 */
class MohSegmentationService
{
    private const SEGMENT_SECONDS = 10;

    private const REDIS_KEY_PREFIX = 'moh:segments:';

    // Segments outlive the key on expiry; the list is rebuildable from the
    // objects, so a self-healing TTL for orphans is acceptable.
    private const REDIS_TTL_SECONDS = 604800; // 7 days

    private const FFMPEG_TIMEOUT_SECONDS = 300;

    /**
     * Ordered list of segment storage paths, empty when not segmented.
     *
     * @return array<int, string>
     */
    public function segmentsFor(Recording $recording): array
    {
        $raw = Redis::get(self::REDIS_KEY_PREFIX.$recording->id);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $segments = json_decode($raw, true);

        return is_array($segments) ? array_values(array_filter($segments, 'is_string')) : [];
    }

    /**
     * Segment the recording and publish the ordered segment list to Redis.
     * Fail-soft: any ffmpeg/IO failure logs a warning and leaves the key absent.
     */
    public function segment(Recording $recording): void
    {
        try {
            $this->doSegment($recording);
        } catch (\Throwable $e) {
            Log::warning('MohSegmentationService: segmentation failed, leaving queue MOH unsegmented', [
                'recording_id' => $recording->id,
                'organization_id' => $recording->organization_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete the Redis list and best-effort delete the segment objects.
     */
    public function invalidate(Recording $recording): void
    {
        Redis::del(self::REDIS_KEY_PREFIX.$recording->id);

        try {
            Storage::disk($this->diskName())
                ->deleteDirectory($this->segmentDirectory($recording));
        } catch (\Throwable $e) {
            Log::warning('MohSegmentationService: failed to delete segment objects', [
                'recording_id' => $recording->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function doSegment(Recording $recording): void
    {
        // Skip work when no queue references the recording anymore (the job
        // may have been dispatched before an unset/swap landed).
        if (! $this->isReferencedByQueue($recording)) {
            Log::info('MohSegmentationService: recording not referenced by any queue, skipping', [
                'recording_id' => $recording->id,
            ]);

            return;
        }

        $disk = Storage::disk($this->diskName());
        $sourcePath = "{$recording->organization_id}/{$recording->file_path}";

        if (! $recording->file_path || ! $disk->exists($sourcePath)) {
            throw new \RuntimeException("source file not found: {$sourcePath}");
        }

        $extension = strtolower(pathinfo($recording->file_path, PATHINFO_EXTENSION)) ?: 'wav';

        $workDir = sys_get_temp_dir().'/mohseg_'.$recording->id.'_'.bin2hex(random_bytes(4));
        mkdir($workDir, 0755, true);

        try {
            $input = $workDir.'/input.'.$extension;
            file_put_contents($input, $disk->get($sourcePath));

            // Segment into a fresh temp dir, upload, then publish the list —
            // re-runs overwrite the same object paths and swap the list last.
            $process = new Process([
                'ffmpeg', '-y', '-i', $input,
                '-f', 'segment',
                '-segment_time', (string) self::SEGMENT_SECONDS,
                '-c', 'copy',
                $workDir.'/%03d.'.$extension,
            ]);
            $process->setTimeout(self::FFMPEG_TIMEOUT_SECONDS);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('ffmpeg exited '.$process->getExitCode().': '.$process->getErrorOutput());
            }

            // [0-9]* keeps matching past segment 999; input.{ext} starts with
            // a letter so it stays excluded.
            $files = glob($workDir.'/[0-9]*.'.$extension) ?: [];
            sort($files, SORT_NATURAL);

            // ponytail: a single segment buys nothing over playing the file
            // itself; leave the recording unsegmented.
            if (count($files) < 2) {
                Log::info('MohSegmentationService: recording shorter than two segment windows, skipping', [
                    'recording_id' => $recording->id,
                    'segments' => count($files),
                ]);

                return;
            }

            $paths = [];
            foreach ($files as $file) {
                $target = $this->segmentDirectory($recording).'/'.basename($file);
                $disk->put($target, file_get_contents($file));
                $paths[] = $target;
            }

            // Re-check before publishing: a queue may have released the
            // recording while ffmpeg was running. The Redis key is the source
            // of truth, so don't resurrect segments for a released recording.
            if (! $this->isReferencedByQueue($recording)) {
                $disk->delete($paths);

                Log::info('MohSegmentationService: recording released during segmentation, skipping publish', [
                    'recording_id' => $recording->id,
                ]);

                return;
            }

            Redis::setex(self::REDIS_KEY_PREFIX.$recording->id, self::REDIS_TTL_SECONDS, json_encode($paths));

            Log::info('MohSegmentationService: MOH segmented', [
                'recording_id' => $recording->id,
                'segments' => count($paths),
            ]);
        } finally {
            foreach (glob($workDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }

    private function isReferencedByQueue(Recording $recording): bool
    {
        return OrganizationScope::bypass(
            fn () => CallQueue::where('moh_recording_id', $recording->id)->exists()
        );
    }

    private function diskName(): string
    {
        return config('recordings.storage_disk', 'recordings');
    }

    private function segmentDirectory(Recording $recording): string
    {
        return "{$recording->organization_id}/moh-segments/{$recording->id}";
    }
}
