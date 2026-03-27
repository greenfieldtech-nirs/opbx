<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ListStatus;
use App\Models\AutoDialerList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job to process a large CSV file (100k+ entries).
 *
 * Splits the file into multiple lists and processes them sequentially.
 */
class ProcessLargeListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 7200; // 2 hours for very large files

    public array $backoff = [60, 300, 900];

    public const int MAX_ENTRIES_PER_LIST = 100000;

    public function __construct(
        public int $sourceListId,
        public string $filePath,
        public string $jobId,
        public int $totalRows,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $sourceList = AutoDialerList::find($this->sourceListId);

        if (! $sourceList) {
            Log::error('ProcessLargeListJob: Source list not found', [
                'list_id' => $this->sourceListId,
            ]);

            return;
        }

        Log::info('ProcessLargeListJob: Starting large file processing', [
            'source_list_id' => $this->sourceListId,
            'total_rows' => $this->totalRows,
            'max_per_list' => self::MAX_ENTRIES_PER_LIST,
        ]);

        // Calculate number of lists needed
        $numLists = ceil($this->totalRows / self::MAX_ENTRIES_PER_LIST);

        $this->updateProgress(0, 'splitting', 0, $numLists);

        // Split the file
        $splitFiles = $this->splitCsvFile($this->filePath, self::MAX_ENTRIES_PER_LIST);

        if (empty($splitFiles)) {
            Log::error('ProcessLargeListJob: Failed to split file', [
                'source_list_id' => $this->sourceListId,
            ]);

            $sourceList->update([
                'status' => ListStatus::FAILED,
                'validation_errors' => [['error' => 'Failed to split large file']],
            ]);

            return;
        }

        $createdLists = [];
        $totalValid = 0;
        $totalInvalid = 0;

        // Process each chunk sequentially
        foreach ($splitFiles as $index => $chunkFile) {
            $chunkNumber = $index + 1;
            $progress = (int) (($chunkNumber / count($splitFiles)) * 100);

            $this->updateProgress($progress, 'processing_chunk', $chunkNumber, count($splitFiles));

            // Create or get list for this chunk
            if ($index === 0) {
                // First chunk uses the original list
                $list = $sourceList;
                $list->update(['status' => ListStatus::PROCESSING]);
            } else {
                // Create new list for this chunk
                $list = AutoDialerList::create([
                    'organization_id' => $sourceList->organization_id,
                    'name' => "{$sourceList->name} (Part {$chunkNumber})",
                    'description' => "Auto-generated part {$chunkNumber} of {$sourceList->name}",
                    'version_number' => $sourceList->version_number,
                    'parent_list_id' => $sourceList->parent_list_id ?? $sourceList->id,
                    'is_latest_version' => false,
                    'status' => ListStatus::PROCESSING,
                ]);
            }

            $createdLists[] = $list->id;

            // Dispatch job for this chunk
            $chunkJobId = "{$this->jobId}_chunk_{$chunkNumber}";
            ProcessListUploadJob::dispatch($list->id, $chunkFile, $chunkJobId)
                ->onQueue('auto-dialer');

            Log::info('ProcessLargeListJob: Dispatched chunk', [
                'source_list_id' => $this->sourceListId,
                'chunk_list_id' => $list->id,
                'chunk_number' => $chunkNumber,
                'total_chunks' => count($splitFiles),
            ]);

            // Wait for this chunk to complete before processing next
            // This ensures sequential processing
            $this->waitForChunkCompletion($chunkJobId);
        }

        // Update source list with summary
        $sourceList->refresh();
        $sourceList->update([
            'status' => ListStatus::READY,
            'processed_at' => now(),
            'description' => ($sourceList->description ?? '').
                " [Split into {$numLists} lists: ".implode(', ', $createdLists).']',
        ]);

        $this->updateProgress(100, 'completed', count($splitFiles), count($splitFiles));

        // Clean up original file
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }

        Log::info('ProcessLargeListJob: Completed large file processing', [
            'source_list_id' => $this->sourceListId,
            'num_lists_created' => count($createdLists),
            'list_ids' => $createdLists,
        ]);
    }

    /**
     * Handle a failed job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessLargeListJob: Failed', [
            'source_list_id' => $this->sourceListId,
            'error' => $exception->getMessage(),
        ]);

        AutoDialerList::where('id', $this->sourceListId)->update([
            'status' => ListStatus::FAILED,
            'validation_errors' => [['error' => 'Large file processing failed']],
        ]);

        $this->updateProgress(100, 'failed');

        // Clean up
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    /**
     * Split a CSV file into multiple files.
     *
     * @param  string  $filePath  Original file path
     * @param  int  $maxRows  Maximum rows per file
     * @return array<int, string> Array of file paths
     */
    private function splitCsvFile(string $filePath, int $maxRows): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        // Read header
        $headers = fgetcsv($handle, escape: '\\');
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $records = [];
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $records[] = array_combine($headers, $row);
        }
        fclose($handle);

        $chunks = array_chunk($records, $maxRows);
        $files = [];

        foreach ($chunks as $chunk) {
            $tempFile = tempnam(sys_get_temp_dir(), 'list_chunk_').'.csv';
            $chunkHandle = fopen($tempFile, 'w');

            if ($chunkHandle === false) {
                continue;
            }

            // Write header
            fputcsv($chunkHandle, $headers);

            // Write records
            foreach ($chunk as $record) {
                fputcsv($chunkHandle, $record);
            }

            fclose($chunkHandle);
            $files[] = $tempFile;
        }

        return $files;
    }

    /**
     * Wait for a chunk to complete processing.
     *
     * @param  string  $chunkJobId  The job ID for the chunk
     */
    private function waitForChunkCompletion(string $chunkJobId): void
    {
        $maxWaitTime = 3600; // 1 hour max wait
        $waitInterval = 5; // Check every 5 seconds
        $elapsed = 0;

        while ($elapsed < $maxWaitTime) {
            $progress = Cache::get("list_upload_progress:{$chunkJobId}");

            if ($progress && ($progress['status'] === 'completed' || $progress['status'] === 'failed')) {
                return; // Chunk done
            }

            sleep($waitInterval);
            $elapsed += $waitInterval;
        }

        Log::warning('ProcessLargeListJob: Timeout waiting for chunk', [
            'chunk_job_id' => $chunkJobId,
        ]);
    }

    /**
     * Update progress in cache.
     */
    private function updateProgress(
        int $percentage,
        string $status,
        ?int $currentChunk = null,
        ?int $totalChunks = null,
    ): void {
        $data = [
            'percentage' => $percentage,
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($currentChunk !== null) {
            $data['current_chunk'] = $currentChunk;
        }

        if ($totalChunks !== null) {
            $data['total_chunks'] = $totalChunks;
        }

        Cache::put("list_upload_progress:{$this->jobId}", $data, now()->addHours(4));
    }
}
