<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Recording;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\MohSegmentationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Segments a queue MOH recording into ~10s chunks (WP1, Scenario 2).
 * Idempotent: re-running re-segments into the same object paths and swaps
 * the Redis list last.
 */
class SegmentQueueMohJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        private readonly int $recordingId
    ) {
        $this->onQueue('default');
    }

    public function handle(MohSegmentationService $segmentation): void
    {
        $recording = OrganizationScope::bypass(fn () => Recording::find($this->recordingId));

        if (! $recording instanceof Recording) {
            Log::info('SegmentQueueMohJob: recording not found, skipping', [
                'recording_id' => $this->recordingId,
            ]);

            return;
        }

        if (! $recording->isUploaded()) {
            Log::info('SegmentQueueMohJob: only uploaded recordings can be segmented, skipping', [
                'recording_id' => $this->recordingId,
                'type' => $recording->type,
            ]);

            return;
        }

        $segmentation->segment($recording);
    }
}
