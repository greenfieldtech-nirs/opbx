<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Events\AutoDialer\ListAssignedToCampaign;
use App\Jobs\ProcessLargeListJob;
use App\Jobs\ProcessListUploadJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing distribution lists.
 *
 * Handles list lifecycle operations: create, upload, copy, version, archive.
 */
class ListManagementService
{
    public const int MAX_ENTRIES_PER_LIST = 100000;

    public function __construct(
        private ListValidationService $validator,
    ) {}

    /**
     * Create a new empty list.
     */
    public function createList(
        int $organizationId,
        string $name,
        ?string $description = null,
    ): AutoDialerList {
        $list = AutoDialerList::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'description' => $description,
            'version_number' => 1,
            'is_latest_version' => true,
            'status' => ListStatus::DRAFT,
        ]);

        Log::info('ListManagementService: Created list', [
            'list_id' => $list->id,
            'organization_id' => $organizationId,
        ]);

        return $list;
    }

    /**
     * Upload CSV file to a list.
     *
     * @return array{job_id: string, list_id: int, is_large_file: bool}
     */
    public function uploadCsv(
        int $listId,
        UploadedFile $file,
    ): array {
        $list = AutoDialerList::findOrFail($listId);

        // Check if list can accept uploads
        if (! $list->status->canUpload()) {
            throw new \InvalidArgumentException('List cannot accept uploads in current status: '.$list->status->label());
        }

        // Store file temporarily (default disk is 'local' which uses storage/app/private)
        $tempPath = $file->store('temp/list_uploads');
        $fullPath = storage_path('app/private/'.$tempPath);

        // Generate job ID
        $jobId = Str::uuid()->toString();

        // Count rows to determine if large file
        $rowCount = $this->countCsvRows($fullPath);
        $isLargeFile = $rowCount > self::MAX_ENTRIES_PER_LIST;

        // Write initial progress to cache so frontend doesn't get "not found"
        Cache::put(
            "list_upload_progress:{$jobId}",
            [
                'percentage' => 0,
                'status' => 'queued',
                'updated_at' => now()->toIso8601String(),
            ],
            now()->addHours(2)
        );

        if ($isLargeFile) {
            // Dispatch large file job
            ProcessLargeListJob::dispatch($list->id, $fullPath, $jobId, $rowCount)
                ->onQueue('auto-dialer');

            Log::info('ListManagementService: Dispatched large file processing', [
                'list_id' => $listId,
                'job_id' => $jobId,
                'row_count' => $rowCount,
            ]);
        } else {
            // Dispatch regular upload job
            ProcessListUploadJob::dispatch($list->id, $fullPath, $jobId)
                ->onQueue('auto-dialer');

            Log::info('ListManagementService: Dispatched upload processing', [
                'list_id' => $listId,
                'job_id' => $jobId,
                'row_count' => $rowCount,
            ]);
        }

        // Update list status
        $list->update([
            'original_filename' => $file->getClientOriginalName(),
            'status' => ListStatus::PROCESSING,
        ]);

        return [
            'job_id' => $jobId,
            'list_id' => $list->id,
            'is_large_file' => $isLargeFile,
            'total_rows' => $rowCount,
        ];
    }

    /**
     * Get upload progress.
     *
     * @return array{percentage: int, status: string, updated_at: string}|null
     */
    public function getUploadProgress(string $jobId): ?array
    {
        return Cache::get("list_upload_progress:{$jobId}");
    }

    /**
     * Add a single destination to a list.
     */
    public function addDestination(
        int $listId,
        string $phoneNumber,
        ?string $description = null,
    ): AutoDialerDestination {
        $list = AutoDialerList::findOrFail($listId);

        // Validate list status
        if (! $list->status->canUpload()) {
            throw new \InvalidArgumentException('Cannot add destinations to list in status: '.$list->status->label());
        }

        // Validate phone number
        $validation = $this->validator->validatePhoneNumber($phoneNumber);

        if (! $validation->valid) {
            throw new \InvalidArgumentException('Invalid phone number: '.$validation->error);
        }

        // Check for duplicate in list
        $existing = AutoDialerDestination::where('list_id', $listId)
            ->where('phone_number', $validation->normalizedNumber)
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('Phone number already exists in this list');
        }

        // Create destination
        $destination = AutoDialerDestination::create([
            'organization_id' => $list->organization_id,
            'list_id' => $list->id,
            'phone_number' => $validation->normalizedNumber,
            'description' => $description,
            'status' => DestinationStatus::PENDING,
            'dial_attempts' => 0,
            'duration' => 0,
            'billsec' => 0,
            'total_duration' => 0,
        ]);

        // Update list statistics
        $list->update([
            'total_rows' => $list->total_rows + 1,
            'valid_rows' => $list->valid_rows + 1,
        ]);

        // If this is the first destination and list is draft, update to ready
        if ($list->status === ListStatus::DRAFT) {
            $list->update(['status' => ListStatus::READY]);
        }

        return $destination;
    }

    /**
     * Add multiple destinations to a list (batch).
     *
     * @param  array<int, array{phone_number: string, description: ?string}>  $destinations
     * @return array{added: int, errors: array<int, string>}
     */
    public function addDestinationsBatch(
        int $listId,
        array $destinations,
    ): array {
        $list = AutoDialerList::findOrFail($listId);

        // Validate list status
        if (! $list->status->canUpload()) {
            throw new \InvalidArgumentException('Cannot add destinations to list in status: '.$list->status->label());
        }

        // Check current count
        $currentCount = $list->destinations()->count();
        if ($currentCount + count($destinations) > self::MAX_ENTRIES_PER_LIST) {
            throw new \InvalidArgumentException(
                'Batch would exceed maximum list size of '.self::MAX_ENTRIES_PER_LIST
            );
        }

        // Batch validate
        $results = $this->validator->batchValidate($destinations);
        $added = 0;
        $errors = [];
        $validEntries = [];

        foreach ($results->results as $index => $result) {
            if ($result->valid) {
                // Check for duplicates within the batch
                $alreadyAdded = false;
                foreach ($validEntries as $existing) {
                    if ($existing['phone_number'] === $result->normalizedNumber) {
                        $alreadyAdded = true;
                        break;
                    }
                }

                if ($alreadyAdded) {
                    $errors[$index] = 'Duplicate phone number in batch';

                    continue;
                }

                $validEntries[] = [
                    'organization_id' => $list->organization_id,
                    'list_id' => $list->id,
                    'phone_number' => $result->normalizedNumber,
                    'description' => $destinations[$index]['description'] ?? null,
                    'status' => DestinationStatus::PENDING,
                    'dial_attempts' => 0,
                    'duration' => 0,
                    'billsec' => 0,
                    'total_duration' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                $errors[$index] = $result->error ?? 'Invalid phone number';
            }
        }

        // Insert valid entries in batch
        if (! empty($validEntries)) {
            // Check for existing duplicates in database
            $existingNumbers = AutoDialerDestination::where('list_id', $listId)
                ->whereIn('phone_number', array_column($validEntries, 'phone_number'))
                ->pluck('phone_number')
                ->toArray();

            $finalEntries = array_filter($validEntries, function ($entry) use ($existingNumbers) {
                return ! in_array($entry['phone_number'], $existingNumbers, true);
            });

            $filteredCount = count($validEntries) - count($finalEntries);

            if (! empty($finalEntries)) {
                \Illuminate\Support\Facades\DB::table('auto_dialer_destinations')->insert($finalEntries);
                $added = count($finalEntries);
            }

            // Update list statistics
            $list->update([
                'total_rows' => $list->total_rows + count($finalEntries),
                'valid_rows' => $list->valid_rows + count($finalEntries),
                'status' => ListStatus::READY,
            ]);
        }

        return [
            'added' => $added,
            'errors' => $errors,
            'duplicates_skipped' => $filteredCount ?? 0,
        ];
    }

    /**
     * Copy a list to create a new independent list.
     */
    public function copyList(
        int $sourceListId,
        string $newName,
    ): AutoDialerList {
        $sourceList = AutoDialerList::findOrFail($sourceListId);

        // Check if name already exists in organization
        $existing = AutoDialerList::where('organization_id', $sourceList->organization_id)
            ->where('name', $newName)
            ->whereNull('archived_at')
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('A list with this name already exists');
        }

        // Use the model's copy method
        $copy = $sourceList->copy($newName);

        Log::info('ListManagementService: Copied list', [
            'source_list_id' => $sourceListId,
            'new_list_id' => $copy->id,
            'new_name' => $newName,
        ]);

        return $copy;
    }

    /**
     * Create a new version of a list.
     */
    public function createNewVersion(
        int $listId,
        UploadedFile $file,
    ): array {
        $list = AutoDialerList::findOrFail($listId);

        // Check if version can be created
        if (! $list->status->canCreateVersion()) {
            throw new \InvalidArgumentException('Cannot create version from list in status: '.$list->status->label());
        }

        // Create new version
        $newVersion = $list->createNewVersion($list->name);

        // Upload file to new version
        return $this->uploadCsv($newVersion->id, $file);
    }

    /**
     * Archive a list.
     */
    public function archiveList(int $listId): AutoDialerList
    {
        $list = AutoDialerList::findOrFail($listId);

        if (! $list->canBeArchived()) {
            throw new \InvalidArgumentException('List cannot be archived in status: '.$list->status->label());
        }

        $list->archive();

        Log::info('ListManagementService: Archived list', [
            'list_id' => $listId,
        ]);

        return $list;
    }

    /**
     * Generate CSV export of a list.
     */
    public function generateCsvExport(int $listId): string
    {
        $list = AutoDialerList::findOrFail($listId);

        $filename = tempnam(sys_get_temp_dir(), 'list_export_').'.csv';
        $handle = fopen($filename, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Failed to create export file');
        }

        // Write header
        fputcsv($handle, ['phone_number', 'description', 'status', 'dial_attempts']);

        // Stream destinations to avoid memory issues with large lists
        $list->destinations()->chunk(1000, function ($destinations) use ($handle) {
            foreach ($destinations as $destination) {
                fputcsv($handle, [
                    $destination->phone_number,
                    $destination->description,
                    $destination->status->value,
                    $destination->dial_attempts,
                ]);
            }
        });

        fclose($handle);

        return $filename;
    }

    /**
     * Count CSV rows (excluding header).
     */
    private function countCsvRows(string $filePath): int
    {
        try {
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                return 0;
            }

            // Skip header
            fgetcsv($handle, escape: '\\');

            $count = 0;
            while (fgetcsv($handle, escape: '\\') !== false) {
                $count++;
            }

            fclose($handle);

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Assign a list to a campaign.
     *
     * @throws \InvalidArgumentException
     */
    public function assignListToCampaign(int $listId, int $campaignId): void
    {
        $list = AutoDialerList::findOrFail($listId);
        $campaign = AutoDialerCampaign::findOrFail($campaignId);

        // Check if list is ready
        if (! $list->isReady()) {
            throw new \InvalidArgumentException('List is not ready for assignment');
        }

        // Check if campaign can accept list
        if (! $campaign->canAcceptList()) {
            throw new \InvalidArgumentException('Campaign cannot accept a list in its current status');
        }

        // Update the list
        $list->update([
            'campaign_id' => $campaignId,
            'status' => ListStatus::IN_USE,
            'used_by_campaign_id' => $campaignId,
            'used_at' => now(),
        ]);

        // Update campaign status if it's in draft
        if ($campaign->status === CampaignStatus::DRAFT) {
            $campaign->update([
                'status' => CampaignStatus::READY,
            ]);
        }

        // Dispatch event
        event(new ListAssignedToCampaign($list, $campaign));

        Log::info('ListManagementService: Assigned list to campaign', [
            'list_id' => $listId,
            'campaign_id' => $campaignId,
        ]);
    }
}
