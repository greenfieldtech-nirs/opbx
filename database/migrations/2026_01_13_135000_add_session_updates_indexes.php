<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to session_updates table.
 *
 * Part of code review fixes (MED-2):
 * SessionUpdateController frequently queries by session_id and organization_id.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('session_updates', function (Blueprint $table) {
            // Primary lookup pattern: organization_id + session_id
            if (!Schema::hasIndex('session_updates', 'session_updates_organization_id_session_id_index')) {
                $table->index(['organization_id', 'session_id'], 'session_updates_organization_id_session_id_index');
            }

            // Ordering pattern: organization_id + created_at
            if (!Schema::hasIndex('session_updates', 'session_updates_organization_id_created_at_index')) {
                $table->index(['organization_id', 'created_at'], 'session_updates_organization_id_created_at_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_updates', function (Blueprint $table) {
            $table->dropIndex('session_updates_organization_id_session_id_index');
            $table->dropIndex('session_updates_organization_id_created_at_index');
        });
    }
};
