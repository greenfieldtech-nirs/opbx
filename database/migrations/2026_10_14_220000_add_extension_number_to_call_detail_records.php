<?php

declare(strict_types=1);

use App\Models\CallDetailRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OPBX extension a CDR belongs to (extension_number), separate from its Caller ID (`from`), on the hot
 * and the warm tier, indexed with organization_id. Additive and idempotent: existing rows whose
 * extension_number is still null are backfilled with the same rule ingest uses
 * (CallDetailRecord::extensionNumberFor), so a rerun only touches rows it could not resolve before.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([[DB::connection(), 'call_detail_records', 'cdr_org_ext_idx'], [DB::connection('cdr_archive'), 'archived_call_detail_records', 'acdr_org_ext_idx']] as [$connection, $table, $index]) {
            $schema = Schema::connection($connection->getName());

            if (! $schema->hasTable($table)) {
                continue;
            }

            if (! $schema->hasColumn($table, 'extension_number')) {
                $schema->table($table, function (Blueprint $table): void {
                    $table->string('extension_number', 20)->nullable()->after('to');
                });
            }

            if (! $schema->hasIndex($table, $index)) {
                $schema->table($table, function (Blueprint $table) use ($index): void {
                    $table->index(['organization_id', 'extension_number'], $index);
                });
            }

            $this->backfill($connection, $table);
        }
    }

    private function backfill(Connection $connection, string $table): void
    {
        $resolved = [];

        $connection->table($table)
            ->whereNull('extension_number')
            ->select('id', 'organization_id', 'subscriber', 'from', 'to')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($connection, $table, &$resolved): void {
                foreach ($rows as $row) {
                    $key = "{$row->organization_id}|{$row->subscriber}|{$row->from}|{$row->to}";
                    $resolved[$key] ??= CallDetailRecord::extensionNumberFor((int) $row->organization_id, $row->subscriber, (string) $row->from, (string) $row->to) ?? false;

                    if ($resolved[$key] !== false) {
                        $connection->table($table)->where('id', $row->id)->update(['extension_number' => $resolved[$key]]);
                    }
                }
            });
    }

    public function down(): void
    {
        foreach ([[Schema::connection(null), 'call_detail_records', 'cdr_org_ext_idx'], [Schema::connection('cdr_archive'), 'archived_call_detail_records', 'acdr_org_ext_idx']] as [$schema, $table, $index]) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'extension_number')) {
                $schema->table($table, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                    $table->dropColumn('extension_number');
                });
            }
        }
    }
};
