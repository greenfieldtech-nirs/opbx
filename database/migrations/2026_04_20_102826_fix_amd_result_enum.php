<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The original migration was updated to use the correct enum values.
        // This migration is kept for historical compatibility with existing installations.
        // For MySQL installations that already ran the original migration:
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE auto_dialer_call_sessions MODIFY COLUMN amd_result ENUM('human', 'voicemail', 'unknown') NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE auto_dialer_call_sessions MODIFY COLUMN amd_result ENUM('human', 'machine', 'unknown') NULL");
        }
    }
};
