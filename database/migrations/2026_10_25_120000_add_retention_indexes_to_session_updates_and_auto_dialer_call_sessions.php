<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the retention columns that the nightly model:prune filters on.
 *
 * Without them every 1000-row prune DELETE is a full table scan. On session_updates
 * (hundreds of thousands of rows) the 03:00 prune ran long enough for the scheduler
 * container to hit its memory limit; the OOM kills orphaned the withoutOverlapping
 * mutexes of the per-minute jobs (platform:health-check, dialer:reconcile-cac) for 24h.
 *
 * Built online (INPLACE, LOCK=NONE): MySQL refuses instead of blocking writes to the
 * hottest table if it cannot build the index online.
 */
return new class extends Migration
{
    private const INDEXES = [
        'session_updates' => ['su_updated_at_idx', 'updated_at'],
        'auto_dialer_call_sessions' => ['adcs_created_at_idx', 'created_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $column]) {
            if (! Schema::hasIndex($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` (`{$column}`), ALGORITHM=INPLACE, LOCK=NONE");
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            if (Schema::hasIndex($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`, ALGORITHM=INPLACE, LOCK=NONE");
            }
        }
    }
};
