<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Extensions - hot path during call routing
        Schema::table('extensions', function (Blueprint $table) {
            if (! $this->indexExists('extensions', ['organization_id', 'extension_number', 'status'])) {
                $table->index(['organization_id', 'extension_number', 'status'], 'ext_org_extnum_status_idx');
            }
        });

        // Ring group members - member lookups
        Schema::table('ring_group_members', function (Blueprint $table) {
            if (! $this->indexExists('ring_group_members', ['ring_group_id', 'extension_id'])) {
                $table->index(['ring_group_id', 'extension_id'], 'rgm_rg_ext_idx');
            }
        });

        // Session updates - active call queries
        Schema::table('session_updates', function (Blueprint $table) {
            if (! $this->indexExists('session_updates', ['organization_id', 'session_id', 'status'])) {
                $table->index(['organization_id', 'session_id', 'status'], 'su_org_session_status_idx');
            }
            if (! $this->indexExists('session_updates', ['organization_id', 'status', 'updated_at'])) {
                $table->index(['organization_id', 'status', 'updated_at'], 'su_org_status_updated_idx');
            }
        });

        // Call logs - chronological queries
        Schema::table('call_logs', function (Blueprint $table) {
            if (! $this->indexExists('call_logs', ['organization_id', 'created_at'])) {
                $table->index(['organization_id', 'created_at'], 'cl_org_created_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropIndex('ext_org_extnum_status_idx');
        });

        Schema::table('ring_group_members', function (Blueprint $table) {
            $table->dropIndex('rgm_rg_ext_idx');
        });

        Schema::table('session_updates', function (Blueprint $table) {
            $table->dropIndex('su_org_session_status_idx');
            $table->dropIndex('su_org_status_updated_idx');
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex('cl_org_created_idx');
        });
    }

    /**
     * Check if an index exists on a table.
     */
    private function indexExists(string $table, array $columns): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            // Check if all columns are in the index
            if (count(array_intersect($columns, $index['columns'])) === count($columns)) {
                return true;
            }
        }

        return false;
    }
};
