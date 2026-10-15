<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-16: drop redundant session_updates indexes (write amplification on the hottest
 * table) and add the missing call_queues (organization_id, status) index.
 *
 * Kept on purpose: idx_event_id. The webhook idempotency check looks up
 * (organization_id, event_id) and no other index has event_id as its leading
 * non-org column. Every dropped index is covered by another one:
 *  - idx_organization_session and session_updates_organization_id_session_id_index
 *    duplicate the prefix of unique_session_event (organization_id, session_id, event_id).
 *  - idx_status is covered by su_org_status_updated_idx / su_org_session_status_idx.
 *  - idx_direction is a low-cardinality single column nothing filters on alone.
 */
return new class extends Migration
{
    private const DROPPED = [
        'idx_organization_session' => ['organization_id', 'session_id'],
        'session_updates_organization_id_session_id_index' => ['organization_id', 'session_id'],
        'idx_status' => ['status'],
        'idx_direction' => ['direction'],
    ];

    public function up(): void
    {
        Schema::table('session_updates', function (Blueprint $table): void {
            foreach (array_keys(self::DROPPED) as $name) {
                if (Schema::hasIndex('session_updates', $name)) {
                    $table->dropIndex($name);
                }
            }
        });

        if (! Schema::hasIndex('call_queues', 'cq_org_status_idx')) {
            Schema::table('call_queues', function (Blueprint $table): void {
                $table->index(['organization_id', 'status'], 'cq_org_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('call_queues', 'cq_org_status_idx')) {
            Schema::table('call_queues', function (Blueprint $table): void {
                $table->dropIndex('cq_org_status_idx');
            });
        }

        Schema::table('session_updates', function (Blueprint $table): void {
            foreach (self::DROPPED as $name => $columns) {
                if (! Schema::hasIndex('session_updates', $name)) {
                    $table->index($columns, $name);
                }
            }
        });
    }
};
