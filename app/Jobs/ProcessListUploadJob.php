<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Models\AutoDialerList;
use App\Services\AutoDialer\ListValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to process a distribution list upload.
 *
 * Validates phone numbers, creates destinations, and updates list statistics.
 */
class ProcessListUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600; // 1 hour for large files

    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     *
     * @param  int  $listId  The list ID being processed
     * @param  string  $filePath  Path to the uploaded CSV file
     * @param  string  $jobId  Unique job ID for progress tracking
     * @param  bool  $isNewVersion  Whether this is creating a new version
     */
    public function __construct(
        public int $listId,
        public string $filePath,
        public string $jobId,
        public bool $isNewVersion = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ListValidationService $validator): void
    {
        $list = AutoDialerList::find($this->listId);

        if (! $list) {
            Log::error('ProcessListUploadJob: List not found', ['list_id' => $this->listId]);
            $this->fail('List not found');

            return;
        }

        // Update status to processing
        $list->update(['status' => ListStatus::PROCESSING]);

        // Initialize progress tracking
        $this->updateProgress(0, 'started');

        try {
            // Validate CSV file
            $this->updateProgress(10, 'validating');
            $result = $validator->validateCsvFile(
                $this->filePath,
                $list->organization_id,
            );

            if (! $result->success) {
                $this->handleValidationFailure($list, $result->error);

                return;
            }

            $this->updateProgress(30, 'processing');

            // Update list statistics
            $list->update([
                'total_rows' => $result->totalRows,
                'valid_rows' => count($result->validRows),
                'invalid_rows' => count($result->invalidRows),
            ]);

            // Store validation errors if any
            if (count($result->invalidRows) > 0) {
                $list->update([
                    'validation_errors' => $result->invalidRows,
                ]);
            }

            // Create destinations in batches
            $this->updateProgress(40, 'creating_destinations');
            $this->createDestinations($list, $result->validRows);

            $this->updateProgress(90, 'finalizing');

            // Update final status
            if (count($result->validRows) === 0) {
                // No valid rows - mark as failed
                $list->update([
                    'status' => ListStatus::FAILED,
                    'processed_at' => now(),
                ]);
                $this->updateProgress(100, 'failed');
            } else {
                // Success - mark as ready
                $list->update([
                    'status' => ListStatus::READY,
                    'processed_at' => now(),
                ]);
                $this->updateProgress(100, 'completed');
            }

            // Clean up file
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }

            Log::info('ProcessListUploadJob: Completed', [
                'list_id' => $this->listId,
                'valid_rows' => count($result->validRows),
                'invalid_rows' => count($result->invalidRows),
                'duplicates' => count($result->duplicates),
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessListUploadJob: Exception', [
                'list_id' => $this->listId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $list->update([
                'status' => ListStatus::FAILED,
                'validation_errors' => [['error' => 'Processing failed: '.$e->getMessage()]],
                'processed_at' => now(),
            ]);

            $this->updateProgress(100, 'error');

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a failed job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessListUploadJob: Failed permanently', [
            'list_id' => $this->listId,
            'error' => $exception->getMessage(),
        ]);

        // Update list status to failed
        AutoDialerList::where('id', $this->listId)->update([
            'status' => ListStatus::FAILED,
            'validation_errors' => [['error' => 'Processing failed after retries']],
            'processed_at' => now(),
        ]);

        $this->updateProgress(100, 'failed');

        // Clean up file
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    /**
     * Create destinations in batches.
     *
     * @param  array<int, array{phone_number: string, description: ?string}>  $validRows
     */
    private function createDestinations(AutoDialerList $list, array $validRows): void
    {
        $batchSize = 1000;
        $totalRows = count($validRows);
        $processedRows = 0;
        $batches = array_chunk($validRows, $batchSize);

        foreach ($batches as $batch) {
            $destinations = [];

            foreach ($batch as $row) {
                $destinations[] = [
                    'organization_id' => $list->organization_id,
                    'list_id' => $list->id,
                    'phone_number' => $row['phone_number'],
                    'description' => $row['description'] ?? null,
                    'status' => DestinationStatus::PENDING,
                    'dial_attempts' => 0,
                    'duration' => 0,
                    'billsec' => 0,
                    'total_duration' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insert batch
            DB::table('auto_dialer_destinations')->insert($destinations);

            $processedRows += count($batch);
            $progress = 40 + (int) (($processedRows / $totalRows) * 50);
            $this->updateProgress($progress, 'creating_destinations');
        }
    }

    /**
     * Handle validation failure.
     */
    private function handleValidationFailure(AutoDialerList $list, string $error): void
    {
        $list->update([
            'status' => ListStatus::FAILED,
            'validation_errors' => [['error' => $error]],
            'processed_at' => now(),
        ]);

        $this->updateProgress(100, 'validation_failed');

        // Clean up file
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    /**
     * Update progress in cache for real-time tracking.
     */
    private function updateProgress(int $percentage, string $status): void
    {
        Cache::put(
            "list_upload_progress:{$this->jobId}",
            [
                'percentage' => $percentage,
                'status' => $status,
                'updated_at' => now()->toIso8601String(),
            ],
            now()->addHours(2)
        );
    }
}
