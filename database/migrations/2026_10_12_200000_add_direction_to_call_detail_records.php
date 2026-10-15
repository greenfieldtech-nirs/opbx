<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store the call direction on the CDR (DATA-6) and index (organization_id, session_id).
 *
 * Additive and idempotent. Existing rows are backfilled in chunks from the latest
 * real session update of their session (the synthetic 'cdr_final_status' row, which
 * always says 'incoming', is only used when nothing else exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('call_detail_records', 'direction')) {
            Schema::table('call_detail_records', function (Blueprint $table): void {
                $table->string('direction', 20)->nullable()->after('to');
            });
        }

        if (! Schema::hasIndex('call_detail_records', 'cdr_org_session_idx')) {
            Schema::table('call_detail_records', function (Blueprint $table): void {
                $table->index(['organization_id', 'session_id'], 'cdr_org_session_idx');
            });
        }

        DB::table('call_detail_records')
            ->whereNull('direction')
            ->whereNotNull('session_id')
            ->select('id', 'organization_id', 'session_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $direction = DB::table('session_updates')
                        ->where('organization_id', $row->organization_id)
                        ->where('session_id', $row->session_id)
                        ->orderByRaw("COALESCE(action, '') = 'cdr_final_status'")
                        ->orderByDesc('id')
                        ->value('direction');

                    if ($direction !== null) {
                        DB::table('call_detail_records')->where('id', $row->id)->update(['direction' => $direction]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasIndex('call_detail_records', 'cdr_org_session_idx')) {
            Schema::table('call_detail_records', function (Blueprint $table): void {
                $table->dropIndex('cdr_org_session_idx');
            });
        }

        if (Schema::hasColumn('call_detail_records', 'direction')) {
            Schema::table('call_detail_records', function (Blueprint $table): void {
                $table->dropColumn('direction');
            });
        }
    }
};
