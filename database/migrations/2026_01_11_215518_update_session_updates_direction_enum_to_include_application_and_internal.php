<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing', 'internal', 'application') NOT NULL");
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
        DB::table('session_updates')
            ->whereIn('direction', ['internal', 'application'])
            ->update(['direction' => 'outgoing']);

        // Then modify the enum back to the original values
        DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing') NOT NULL");
    }
};
