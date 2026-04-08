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
        // Skip for SQLite - it doesn't support MODIFY COLUMN
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Modify enum columns to include new AI action types (MySQL only)
        DB::statement("ALTER TABLE business_hours_schedules MODIFY COLUMN open_hours_action_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer') NOT NULL DEFAULT 'extension'");
        DB::statement("ALTER TABLE business_hours_schedules MODIFY COLUMN closed_hours_action_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer') NOT NULL DEFAULT 'extension'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip for SQLite
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Revert to original enum values (MySQL only)
        DB::statement("ALTER TABLE business_hours_schedules MODIFY COLUMN open_hours_action_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu') NOT NULL DEFAULT 'extension'");
        DB::statement("ALTER TABLE business_hours_schedules MODIFY COLUMN closed_hours_action_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu') NOT NULL DEFAULT 'extension'");
    }
};
