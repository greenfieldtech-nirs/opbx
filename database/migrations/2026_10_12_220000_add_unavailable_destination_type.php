<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the 'unavailable' routing destination ("Destination doesn't exist" + hangup) that user delete
 * switches routes to when the target extension's user is removed.
 *
 * Each column's current ENUM definition is read from the database and 'unavailable' is appended, so the
 * existing values, default and nullability are preserved exactly. Columns that are not ENUMs are skipped.
 * Auto-dialer campaigns are not touched: they cannot point at an extension.
 */
return new class extends Migration
{
    private const VALUE = 'unavailable';

    /** @var list<array{string, string}> */
    private const COLUMNS = [
        ['ring_groups', 'fallback_action'],
        ['call_queues', 'fallback_action'],
        ['ai_assistant_load_balancers', 'fallback_action'],
        ['ivr_menu_options', 'destination_type'],
        ['ivr_menus', 'failover_destination_type'],
        ['did_numbers', 'routing_type'],
        ['business_hours_schedules', 'open_hours_action_type'],
        ['business_hours_schedules', 'closed_hours_action_type'],
        ['call_tracking_campaigns', 'destination_type'],
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $type): string => str_contains($type, "'".self::VALUE."'")
            ? $type
            : substr($type, 0, -1).",'".self::VALUE."')");
    }

    /**
     * Refuses while any row still uses 'unavailable' (there is no safe value to map it back to: business
     * hours and DIDs have no hangup type). Re-point those routes first, then roll back.
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
