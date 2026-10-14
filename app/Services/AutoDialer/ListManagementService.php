<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Events\AutoDialer\ListAssignedToCampaign;
use App\Jobs\ProcessLargeListJob;
use App\Jobs\ProcessListUploadJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\AutoDialerListVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * @param  array<string, mixed>  $mapping
     * @return array{job_id: string, list_id: int, is_large_file: bool, total_rows: int}
     */
    public function uploadCsv(
        int $listId,
        UploadedFile $file,
        array $mapping = [],
    ): array {
        $list = AutoDialerList::findOrFail($listId);

        // Check if list can accept uploads
        if (! $list->status->canUpload()) {
            throw new \InvalidArgumentException('List cannot accept uploads in current status: '.$list->status->label());
        }

        // Store file temporarily (default disk is 'local' which uses storage/app/private)
        $tempPath = $file->store('temp/list_uploads');
        $fullPath = Storage::disk('local')->path($tempPath);

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
            ProcessLargeListJob::dispatch($list->id, $fullPath, $jobId, $rowCount, $mapping);

            Log::info('ListManagementService: Dispatched large file processing', [
                'list_id' => $listId,
                'job_id' => $jobId,
                'row_count' => $rowCount,
            ]);
        } else {
            // Dispatch regular upload job
            ProcessListUploadJob::dispatch($list->id, $fullPath, $jobId, false, $mapping);

            Log::info('ListManagementService: Dispatched upload processing', [
                'list_id' => $listId,
                'job_id' => $jobId,
                'row_count' => $rowCount,
            ]);
        }

        // Update list status
        $list->update([
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
            'uploaded_at' => now(),
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
     * Preview a CSV file for column mapping.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, total_rows: int, has_header: bool}
     */
    public function previewCsv(string $filePath, bool $hasHeader = true): array
    {
        return $this->validator->parseCsvPreview($filePath, $hasHeader, 5);
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
        ?string $name = null,
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
            'name' => $name,
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
     * @param  array<int, array{phone_number: string, name: ?string}>  $destinations
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
                    'name' => $destinations[$index]['name'] ?? null,
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
                DB::table('auto_dialer_destinations')->insert($finalEntries);
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
     * Replace a list's entries with a new CSV. The entries being replaced are kept as a
     * previous version (see snapshotVersion()) before they are deleted.
     *
     * @param  array<string, mixed>  $mapping
     * @return array{job_id: string, list_id: int, is_large_file: bool, total_rows: int, version_number: int}
     */
    public function updateListWithBackup(int $listId, UploadedFile $file, array $mapping = []): array
    {
        $list = AutoDialerList::findOrFail($listId);

        // Check if list can be updated
        if (! $list->status->canCreateVersion()) {
            throw new \InvalidArgumentException('Cannot update list in status: '.$list->status->label());
        }

        $version = DB::transaction(function () use ($list): AutoDialerListVersion {
            $version = $this->snapshotVersion($list);

            $list->destinations()->delete();
            $list->update([
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'status' => ListStatus::PENDING,
                'version_number' => $list->version_number + 1,
            ]);

            return $version;
        });

        Log::info('ListManagementService: Kept previous version and cleared old destinations', [
            'list_id' => $listId,
            'version_id' => $version->id,
            'entries' => $version->entries_count,
            'new_version' => $list->version_number,
        ]);

        // Upload new CSV
        $result = $this->uploadCsv($listId, $file, $mapping);

        return [
            ...$result,
            'version_number' => $list->version_number,
        ];
    }

    /**
     * Copy the list's current entries, as they are now, into a new previous-version record.
     */
    private function snapshotVersion(AutoDialerList $list): AutoDialerListVersion
    {
        $days = config('retention.auto_dialer_list_versions_days');
        $source = fn () => DB::table('auto_dialer_destinations')->where('list_id', $list->id);
        $bounds = $source()->selectRaw('COUNT(*) AS total, MIN(id) AS lo, MAX(id) AS hi')->first();

        $version = $list->versions()->create([
            'organization_id' => $list->organization_id,
            'version_number' => $list->version_number,
            'original_filename' => $list->original_filename,
            'uploaded_by' => $list->uploaded_by,
            // Lists uploaded before uploads were recorded: processing finished shortly after the upload.
            'uploaded_at' => $list->uploaded_at ?? $list->processed_at,
            'replaced_at' => now(),
            'expires_at' => $days === null ? null : now()->addDays((int) $days),
            'entries_count' => (int) $bounds->total,
            'total_rows' => $list->total_rows,
            'valid_rows' => $list->valid_rows,
            'invalid_rows' => $list->invalid_rows,
        ]);

        $columns = ['phone_number', 'name', 'batch_identifier', 'metadata', 'status', 'dial_attempts',
            'last_dialed_at', 'last_disposition', 'duration', 'created_at', 'updated_at'];
        $chunk = 10000;
        for ($from = (int) $bounds->lo; $bounds->lo !== null && $from <= (int) $bounds->hi; $from += $chunk) {
            DB::table('auto_dialer_list_version_destinations')->insertUsing(
                ['version_id', 'organization_id', ...$columns],
                $source()->whereBetween('id', [$from, $from + $chunk - 1])->orderBy('id')
                    ->select([DB::raw((int) $version->id), 'organization_id', ...$columns]),
            );
        }

        return $version;
    }

    /**
     * Write a previous version's entries as CSV to the output stream.
     */
    public function writeVersionCsv(AutoDialerListVersion $version): void
    {
        $handle = fopen('php://output', 'w');
        fputcsv($handle, ['phone_number', 'name', 'batch_identifier', 'metadata', 'status', 'dial_attempts', 'last_disposition', 'last_dialed_at']);

        $version->destinations()->chunkById(1000, function ($destinations) use ($handle): void {
            foreach ($destinations as $destination) {
                fputcsv($handle, [
                    $destination->phone_number,
                    $destination->name,
                    $destination->batch_identifier,
                    $destination->metadata ? json_encode($destination->metadata) : null,
                    $destination->status->value,
                    $destination->dial_attempts,
                    $destination->last_disposition,
                    $destination->last_dialed_at?->format('Y-m-d H:i:s'),
                ]);
            }
        });

        fclose($handle);
    }

    /**
     * Delete a list completely: its entries (and, by cascade, their dial sessions and its
     * previous versions), the list row itself, and any JSON backup files left from re-uploads
     * made before previous versions were kept in the database.
     */
    public function deleteList(AutoDialerList $list): void
    {
        $lists = collect([$list, ...$list->allParts()]);

        DB::transaction(function () use ($lists): void {
            foreach ($lists as $each) {
                $each->destinations()->delete();
                $each->delete();
            }
        });

        $prefixes = $lists->map(fn (AutoDialerList $each): string => "list_{$each->id}_v")->all();
        $disk = Storage::disk('list_backups');
        $disk->delete(array_values(array_filter(
            $disk->files(),
            fn (string $file): bool => Str::startsWith($file, $prefixes)
        )));

        Log::info('ListManagementService: Deleted list', ['list_id' => $list->id, 'part_ids' => $lists->skip(1)->pluck('id')->values()->all()]);
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

        if ($list->isAssigned()) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot archive list "%s": it is assigned to campaign "%s". Unassign it first.',
                $list->name,
                $list->campaign?->name ?? '#'.$list->campaign_id
            ));
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
        fputcsv($handle, ['phone_number', 'name', 'batch_identifier', 'metadata', 'status', 'dial_attempts']);

        // Stream destinations to avoid memory issues with large lists
        $list->destinations()->chunk(1000, function ($destinations) use ($handle) {
            foreach ($destinations as $destination) {
                fputcsv($handle, [
                    $destination->phone_number,
                    $destination->name,
                    $destination->batch_identifier,
                    $destination->metadata ? json_encode($destination->metadata) : null,
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

        // Dispatch event
        event(new ListAssignedToCampaign($list, $campaign, Auth::id()));

        Log::info('ListManagementService: Assigned list to campaign', [
            'list_id' => $listId,
            'campaign_id' => $campaignId,
        ]);
    }

    /**
     * Unassign a list from its campaign, keeping the list and its entries.
     *
     * Both unassign endpoints (campaign and distribution list) go through here.
     *
     * @throws AuthorizationException while the list's campaign is active (403)
     * @throws \InvalidArgumentException when the list is not assigned or its entries have started processing
     */
    public function unassignList(AutoDialerList $list): void
    {
        $refusal = $list->unassignRefusal();

        if ($refusal === AutoDialerList::CAMPAIGN_ACTIVE_MESSAGE) {
            throw new AuthorizationException($refusal);
        }

        if ($refusal !== null) {
            throw new \InvalidArgumentException($refusal);
        }

        $campaignId = $list->campaign_id;

        $list->update([
            'campaign_id' => null,
            'status' => ListStatus::READY,
            'used_by_campaign_id' => null,
            'used_at' => null,
        ]);

        Log::info('ListManagementService: Unassigned list from campaign', [
            'list_id' => $list->id,
            'campaign_id' => $campaignId,
        ]);
    }
}
