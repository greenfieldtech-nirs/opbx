<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queue calls answered before OPBX recorded the answering extension: their CDRs (hot and warm tier) that are
 * still unassigned take the answering agent's extension from queue_calls. Data-only and idempotent: only rows
 * whose extension_number is null are touched, so a rerun changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queue_calls')) {
            return;
        }

        $tiers = array_filter(
            [[DB::connection(), 'call_detail_records'], [DB::connection('cdr_archive'), 'archived_call_detail_records']],
            fn (array $tier) => Schema::connection($tier[0]->getName())->hasTable($tier[1]),
        );

        DB::table('queue_calls')
            ->where('disposition', 'answered')
            ->whereNotNull('agent_user_id')
            ->select('id', 'organization_id', 'call_id', 'session_token')
            ->selectSub(fn ($q) => $q->from('extensions')
                ->whereColumn('extensions.user_id', 'queue_calls.agent_user_id')
                ->whereColumn('extensions.organization_id', 'queue_calls.organization_id')
                ->orderBy('extensions.id')
                ->limit(1)
                ->select('extension_number'), 'extension_number')
            ->chunkById(500, function ($rows) use ($tiers): void {
                foreach ($rows as $row) {
                    if ($row->extension_number === null) {
                        continue;
                    }

                    foreach ($tiers as [$connection, $table]) {
                        $connection->table($table)
                            ->where('organization_id', $row->organization_id)
                            ->whereIn('session_token', array_values(array_filter([$row->session_token, $row->call_id])))
                            ->whereNull('extension_number')
                            ->update(['extension_number' => $row->extension_number]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data-only backfill: nothing to undo.
    }
};
