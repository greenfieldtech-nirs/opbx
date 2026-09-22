<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'call_queue' as a routing/destination type across the routing tables.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE did_numbers MODIFY routing_type ENUM('extension', 'ring_group', 'business_hours', 'conference_room', 'ai_assistant', 'ai_load_balancer', 'ivr_menu', 'call_tracking', 'call_queue') NOT NULL DEFAULT 'extension'");

        DB::statement("ALTER TABLE ivr_menu_options MODIFY destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'business_hours', 'ai_assistant', 'ai_load_balancer', 'call_queue') NOT NULL");

        DB::statement("ALTER TABLE ivr_menus MODIFY failover_destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup', 'call_queue') NOT NULL DEFAULT 'hangup'");

        Schema::table('ring_groups', function (Blueprint $table) {
            $table->foreignId('fallback_call_queue_id')->nullable()->after('fallback_ai_load_balancer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE did_numbers MODIFY routing_type ENUM('extension', 'ring_group', 'business_hours', 'conference_room', 'ai_assistant', 'ai_load_balancer', 'ivr_menu', 'call_tracking') NOT NULL DEFAULT 'extension'");

        DB::statement("ALTER TABLE ivr_menu_options MODIFY destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'business_hours', 'ai_assistant', 'ai_load_balancer') NOT NULL");

        DB::statement("ALTER TABLE ivr_menus MODIFY failover_destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup') NOT NULL DEFAULT 'hangup'");

        Schema::table('ring_groups', function (Blueprint $table) {
            $table->dropColumn('fallback_call_queue_id');
        });
    }
};
