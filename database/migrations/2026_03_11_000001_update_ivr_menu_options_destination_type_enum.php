<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, we need to modify the enum column
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'mysql') {
            // Use raw SQL to modify the enum
            DB::statement("ALTER TABLE ivr_menu_options MODIFY COLUMN destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu', 'business_hours', 'ai_assistant', 'ai_load_balancer') NOT NULL");
        } else {
            // For other databases, we might need a different approach
            Schema::table('ivr_menu_options', function (Blueprint $table) {
                $table->string('destination_type', 50)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE ivr_menu_options MODIFY COLUMN destination_type ENUM('extension', 'ring_group', 'conference_room', 'ivr_menu') NOT NULL");
        } else {
            Schema::table('ivr_menu_options', function (Blueprint $table) {
                $table->string('destination_type', 50)->change();
            });
        }
    }
};
