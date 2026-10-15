<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Business Hours Call Queue action (BusinessHoursActionType::CALL_QUEUE) was offered by the API and UI
 * but the two ENUM columns never accepted it, so a schedule with that action could not be saved.
 *
 * Each column's current ENUM definition is read from the database and 'call_queue' is appended, so the
 * existing values, default and nullability are preserved. Idempotent; non-ENUM columns are skipped.
 */
return new class extends Migration
{
    private const VALUE = 'call_queue';

    /** @var list<array{string, string}> */
    private const COLUMNS = [
        ['business_hours_schedules', 'open_hours_action_type'],
        ['business_hours_schedules', 'closed_hours_action_type'],
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $type): string => str_contains($type, "'".self::VALUE."'")
            ? $type
            : substr($type, 0, -1).",'".self::VALUE."')");
    }

    /**
     * Refuses while any schedule still uses 'call_queue' (no safe value to map it back to).
     * Re-point those schedules first, then roll back.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, self::VALUE)->exists()) {
                throw new RuntimeException("Cannot roll back: {$table}.{$column} still has rows set to '".self::VALUE."'. Re-point those routes first.");
            }
        }

        $this->rewrite(fn (string $type): string => str_replace(",'".self::VALUE."'", '', $type));
    }

    /** @param  callable(string): string  $newType */
    private function rewrite(callable $newType): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $info = DB::selectOne("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);
            $type = (string) $info->Type;
            if (! str_starts_with(strtolower($type), 'enum(') || $newType($type) === $type) {
                continue;
            }

            $nullable = $info->Null === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $info->Default === null
                ? ($info->Null === 'YES' ? ' DEFAULT NULL' : '')
                : ' DEFAULT '.DB::getPdo()->quote((string) $info->Default);

            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$newType($type)} {$nullable}{$default}");
        }
    }
};
