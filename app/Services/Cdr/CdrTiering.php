<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tiered CDR storage: hot (call_detail_records) -> warm (archived_call_detail_records on the
 * cdr_archive connection) -> cold (gzip CSV per org and UTC hour on the cdr_archive disk).
 *
 * Every move is copy -> verify -> delete the source, so a failed verify never loses rows and
 * a rerun converges. Tenant scopes are bypassed by using the query builder directly.
 */
class CdrTiering
{
    private const TABLE_HOT = 'call_detail_records';

    private const TABLE_WARM = 'archived_call_detail_records';

    private const CHUNK = 200;

    /** Move hot rows older than CDR_HOT_MONTHS to the warm table, keeping their ids. */
    public function hotToWarm(): int
    {
        $cutoff = $this->cutoff((int) config('retention.cdr_hot_months'));
        $moved = 0;

        while (($rows = DB::table(self::TABLE_HOT)->where('session_timestamp', '<', $cutoff)->orderBy('id')->limit(self::CHUNK)->get())->isNotEmpty()) {
            $ids = $rows->pluck('id')->all();
            $payload = $rows->map(fn (object $row): array => (array) $row)->all();

            $this->warm()->upsert($payload, ['id'], array_values(array_diff(array_keys($payload[0]), ['id'])));

            if ($this->warmCount($ids) !== count($ids)) {
                throw new \RuntimeException('CDR hot->warm verify failed; source rows kept.');
            }

            DB::table(self::TABLE_HOT)->whereIn('id', $ids)->delete();
            $moved += count($ids);
        }

        return $moved;
    }

    /**
     * Write warm rows older than CDR_WARM_MONTHS to cold storage, one gzip CSV per (org, UTC hour),
     * then delete them from the warm table. An existing hour file is merged by id, never overwritten
     * with fewer rows. Returns the number of files written.
     */
    public function warmToCold(): int
    {
        $cutoff = $this->cutoff((int) config('retention.cdr_warm_months'));
        $files = 0;

        while ($first = $this->warm()->where('session_timestamp', '<', $cutoff)->orderBy('session_timestamp')->orderBy('id')->first(['organization_id', 'session_timestamp'])) {
            $hour = CarbonImmutable::parse($first->session_timestamp, 'UTC')->startOfHour();
            $this->archiveHour((int) $first->organization_id, $hour, $cutoff);
            $files++;
        }

        return $files;
    }

    /** Delete cold hour files older than CDR_COLD_RETENTION_YEARS. Returns the number deleted. */
    public function expireCold(): int
    {
        $cutoff = $this->cutoff(12 * (int) config('retention.cdr_cold_retention_years'));
        $disk = Storage::disk('cdr_archive');
        $deleted = 0;

        foreach ($disk->directories() as $orgDir) {
            foreach ($disk->directories($orgDir) as $yearDir) {
                if ((int) basename($yearDir) > $cutoff->year) {
                    continue;
                }

                foreach ($disk->allFiles($yearDir) as $file) {
                    // {org}/YYYY/MM/DD/YYYY-MM-DD-HH.csv.gz
                    if (preg_match('/(\d{4})-(\d{2})-(\d{2})-(\d{2})\.csv\.gz$/', $file, $m)
                        && CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], 0, 0, 'UTC') < $cutoff) {
                        $disk->delete($file);
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    private function archiveHour(int $orgId, CarbonImmutable $hour, CarbonImmutable $cutoff): void
    {
        $rows = $this->warm()->where('organization_id', $orgId)
            ->where('session_timestamp', '>=', $hour)->where('session_timestamp', '<', $hour->addHour())
            ->where('session_timestamp', '<', $cutoff)->orderBy('id')->get()
            ->map(function (object $row): array {
                $row = (array) $row;
                unset($row['raw_cdr']);

                return $row;
            })->keyBy('id');

        $disk = Storage::disk('cdr_archive');
        $path = sprintf('%d/%s/%s.csv.gz', $orgId, $hour->format('Y/m/d'), $hour->format('Y-m-d-H'));
        $header = array_keys($rows->first());

        $existing = $disk->exists($path) ? collect($this->parseCsv((string) gzdecode($disk->get($path))))->keyBy('id') : collect();
        $merged = $rows->union($existing)->sortKeys();

        $disk->put($path, gzencode($this->toCsv($header, $merged->all())));

        if ($this->coldIds($path) !== $merged->keys()->map(fn ($id): string => (string) $id)->all()) {
            throw new \RuntimeException("CDR warm->cold verify failed for {$path}; source rows kept.");
        }

        $this->warm()->whereIn('id', $rows->keys())->delete();
    }

    /** @return list<string> sorted ids in a cold file, read back from storage */
    protected function coldIds(string $path): array
    {
        $ids = array_map(fn (array $row): string => (string) $row['id'], $this->parseCsv((string) gzdecode(Storage::disk('cdr_archive')->get($path))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  list<string>  $header
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function toCsv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): mixed => $row[$column] ?? '', $header), escape: '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /** @return list<array<string, string|null>> */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }

    /** @param  list<int>  $ids */
    protected function warmCount(array $ids): int
    {
        return $this->warm()->whereIn('id', $ids)->count();
    }

    protected function cutoff(int $months): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subMonthsNoOverflow($months);
    }

    private function warm(): Builder
    {
        return DB::connection('cdr_archive')->table(self::TABLE_WARM);
    }
}
