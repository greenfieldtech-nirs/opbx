<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Update the direction enum to include 'application' and 'internal' values
     * to support session-update webhooks from Cloudonix.
     */
    public function up(): void
    {
        // MySQL supports ALTER TABLE MODIFY COLUMN for ENUM
        if (DB::getDriverName() === 'mysql') {
            // Check if column exists first
            if (Schema::hasColumn('session_updates', 'direction')) {
                DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing', 'internal', 'application') NOT NULL");
            }
        }
        // SQLite doesn't support ALTER COLUMN, skip for SQLite since tests don't require this specific enum
        elseif (DB::getDriverName() === 'sqlite') {
            // For SQLite, we can't easily modify ENUMs, but tests don't depend on this specific column constraint
            // The column will still work with TEXT values in SQLite
        }
    }

    /**
     * Reverse the migrations.
     *
     * Revert the direction enum back to only 'incoming' and 'outgoing',
     * and update any 'application' or 'internal' records to 'outgoing'.
     */
    public function down(): void
    {
        // First update any records with the new enum values to 'outgoing'
        if (Schema::hasColumn('session_updates', 'direction')) {
            DB::table('session_updates')
                ->whereIn('direction', ['internal', 'application'])
                ->update(['direction' => 'outgoing']);

            // MySQL approach
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing') NOT NULL");
            }
            // SQLite approach - skip for SQLite
            elseif (DB::getDriverName() === 'sqlite') {
                // SQLite doesn't support ALTER COLUMN, values are already updated above
            }
        }
    }
};
