<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Campaign List Service
 *
 * Handles list upload, CSV parsing, validation, and processing for auto-dialer campaigns.
 */
class CampaignListService
{
    private const BATCH_SIZE = 1000;

    private const E164_PATTERN = '/^\+[1-9]\d{1,14}$/';

    /**
     * Upload and process a destination list for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to upload the list for
     * @param  UploadedFile  $file  The uploaded CSV file
     * @param  string|null  $name  Optional custom name for the list
     * @return array<string, mixed> The upload result with list metadata
     */
    public function uploadList(AutoDialerCampaign $campaign, UploadedFile $file, ?string $name = null): array
    {
        $path = $file->store('auto-dialer-lists');

        $list = AutoDialerList::create([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'name' => $name ?? $file->getClientOriginalName(),
            'status' => 'processing',
            'original_filename' => $file->getClientOriginalName(),
        ]);

        $result = $this->processCsvFile($path, $campaign, $list);

        Storage::delete($path);

        return [
            'list_id' => $list->id,
            'total_rows' => $result['total_rows'],
            'valid_rows' => $result['valid_rows'],
            'invalid_rows' => $result['invalid_rows'],
        ];
    }

    /**
     * Delete a list from a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to delete the list from
     */
    public function deleteList(AutoDialerCampaign $campaign): void
    {
        if ($campaign->list) {
            $campaign->list->destinations()->delete();
            $campaign->list->delete();
        }
    }

    /**
     * Process a CSV file and create destinations.
     *
     * @param  string  $path  The storage path to the CSV file
     * @param  AutoDialerCampaign  $campaign  The campaign to associate destinations with
     * @param  AutoDialerList  $list  The list record to update
     * @return array<string, int> Processing statistics
     */
    private function processCsvFile(string $path, AutoDialerCampaign $campaign, AutoDialerList $list): array
    {
        $fullPath = Storage::path($path);
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            return ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0];
        }

        $header = fgetcsv($handle, escape: '\\');
        if (! $header) {
            fclose($handle);

            return ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0];
        }

        $stats = [
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
        ];
        $destinations = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $stats['total_rows']++;

            if (count($row) < 1) {
                $stats['invalid_rows']++;

                continue;
            }

            $phoneNumber = trim($row[0]);
            $description = trim($row[1] ?? '');

            if (! $this->isValidPhoneNumber($phoneNumber)) {
                $stats['invalid_rows']++;

                continue;
            }

            $destinations[] = [
                'organization_id' => $campaign->organization_id,
                'list_id' => $list->id,
                'phone_number' => $phoneNumber,
                'description' => $description,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $stats['valid_rows']++;

            if (count($destinations) >= self::BATCH_SIZE) {
                AutoDialerDestination::insert($destinations);
                $destinations = [];
            }
        }

        fclose($handle);

        if (! empty($destinations)) {
            AutoDialerDestination::insert($destinations);
        }

        $this->removeDuplicateDestinations($list->id);

        $uniqueCount = AutoDialerDestination::where('list_id', $list->id)->count();

        $list->update([
            'status' => 'ready',
            'processed_at' => now(),
            'total_rows' => $stats['total_rows'],
            'valid_rows' => $uniqueCount,
            'invalid_rows' => $stats['invalid_rows'] + ($stats['valid_rows'] - $uniqueCount),
        ]);

        $campaign->update([
            'total_destinations' => $uniqueCount,
            'pending_calls' => $uniqueCount,
        ]);

        return [
            'total_rows' => $stats['total_rows'],
            'valid_rows' => $uniqueCount,
            'invalid_rows' => $stats['invalid_rows'] + ($stats['valid_rows'] - $uniqueCount),
        ];
    }

    /**
     * Validate a phone number against E.164 format.
     *
     * @param  string  $phoneNumber  The phone number to validate
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        return preg_match(self::E164_PATTERN, $phoneNumber) === 1;
    }

    /**
     * Remove duplicate phone numbers from a list, keeping the first occurrence.
     *
     * @param  int  $listId  The list ID to deduplicate
     */
    private function removeDuplicateDestinations(int $listId): void
    {
        $duplicates = AutoDialerDestination::select('phone_number')
            ->where('list_id', $listId)
            ->groupBy('phone_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone_number');

        foreach ($duplicates as $phoneNumber) {
            $ids = AutoDialerDestination::where('list_id', $listId)
                ->where('phone_number', $phoneNumber)
                ->orderBy('id')
                ->pluck('id');

            $ids->shift();
            AutoDialerDestination::whereIn('id', $ids)->delete();
        }
    }

    /**
     * Check if a campaign has a valid list.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to check
     */
    public function hasValidList(AutoDialerCampaign $campaign): bool
    {
        return $campaign->list !== null && $campaign->list->status === 'ready';
    }

    /**
     * Get list details for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get list details for
     * @return array<string, mixed>|null The list details or null if no list exists
     */
    public function getListDetails(AutoDialerCampaign $campaign): ?array
    {
        if (! $campaign->list) {
            return null;
        }

        return [
            'id' => $campaign->list->id,
            'name' => $campaign->list->name,
            'status' => $campaign->list->status->value ?? $campaign->list->status,
            'total_rows' => $campaign->list->total_rows,
            'valid_rows' => $campaign->list->valid_rows,
            'invalid_rows' => $campaign->list->invalid_rows,
            'processed_at' => $campaign->list->processed_at?->toIso8601String(),
        ];
    }
}
