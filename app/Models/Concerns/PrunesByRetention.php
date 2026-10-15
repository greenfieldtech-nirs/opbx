<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;

/**
 * Prune rows older than config('retention.<RETENTION_KEY>') days, by the model's
 * RETENTION_COLUMN. A null/empty setting disables pruning.
 *
 * The tenant scope is removed because model:prune runs without an authenticated user,
 * where the scope would match zero rows.
 */
trait PrunesByRetention
{
    use MassPrunable;

    public function prunable(): Builder
    {
        $days = config('retention.'.static::RETENTION_KEY);
        $query = static::withoutGlobalScopes();

        return $days === null
            ? $query->whereRaw('1 = 0')
            : $query->where(static::RETENTION_COLUMN, '<', now()->subDays((int) $days));
    }
}
