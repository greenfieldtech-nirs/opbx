<?php

declare(strict_types=1);

namespace App\Services\Cdr;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
