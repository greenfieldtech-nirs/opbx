<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow ring groups to fall back to a call queue.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE ring_groups MODIFY fallback_action ENUM('extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup', 'call_queue') NOT NULL DEFAULT 'hangup'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE ring_groups MODIFY fallback_action ENUM('extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup') NOT NULL DEFAULT 'hangup'");
    }
};
