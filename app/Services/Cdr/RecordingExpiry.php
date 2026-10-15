<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Deletes call-recording objects ({org}/call-recordings/*) older than the recording retention and
 * resets the matching hot/warm CDRs. IVR/prompt recordings live elsewhere and are not touched.
 */
class RecordingExpiry
{
    /** Retention in months: RECORDING_RETENTION_MONTHS, never longer than the CDR cold retention. */
    public function retentionMonths(): int
    {
        $cap = 12 * (int) config('retention.cdr_cold_retention_years');
        $configured = config('retention.recording_retention_months');

        return $configured === null ? $cap : min((int) $configured, $cap);
    }

    /** @return int number of recording objects deleted */
    public function expire(): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subMonthsNoOverflow($this->retentionMonths())->getTimestamp();
        $disk = Storage::disk('recordings');
        $deleted = 0;

        foreach ($disk->directories() as $orgDir) {
            if (! ctype_digit($orgDir)) {
                continue;
            }

            foreach ($disk->getDriver()->listContents("{$orgDir}/call-recordings", false) as $item) {
                if (! $item instanceof FileAttributes || $item->lastModified() === null || $item->lastModified() >= $cutoff) {
                    continue;
                }

                $disk->delete($item->path());
                $this->resetCdrs((int) $orgDir, substr($item->path(), strlen($orgDir) + 1));
                $deleted++;
            }
        }

        return $deleted;
    }

    private function resetCdrs(int $orgId, string $relativePath): void
    {
        $reset = ['recording_status' => 'none', 'recording_stored_path' => null, 'recording_mime_type' => null, 'recording_duration' => null];

        foreach ([DB::table('call_detail_records'), DB::connection('cdr_archive')->table('archived_call_detail_records')] as $table) {
            $table->where('organization_id', $orgId)->where('recording_stored_path', $relativePath)->update($reset);
        }
    }
}
