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
        // Add ai_assistant and ai_load_balancer to ivr_menu_options.destination_type ENUM
        DB::statement("ALTER TABLE ivr_menu_options MODIFY COLUMN destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer') NOT NULL");

        // Add ai_assistant and ai_load_balancer to ivr_menus.failover_destination_type ENUM
        DB::statement("ALTER TABLE ivr_menus MODIFY COLUMN failover_destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup') NOT NULL DEFAULT 'hangup'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove ai_assistant and ai_load_balancer from ivr_menu_options.destination_type ENUM
        DB::statement("ALTER TABLE ivr_menu_options MODIFY COLUMN destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu') NOT NULL");

        // Remove ai_assistant and ai_load_balancer from ivr_menus.failover_destination_type ENUM
        DB::statement("ALTER TABLE ivr_menus MODIFY COLUMN failover_destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'hangup') NOT NULL DEFAULT 'hangup'");
    }
};
