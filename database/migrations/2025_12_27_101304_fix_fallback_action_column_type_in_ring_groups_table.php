<?php

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
        // Change fallback_action from JSON to ENUM
        DB::statement("ALTER TABLE ring_groups MODIFY COLUMN fallback_action ENUM('extension', 'hangup') DEFAULT 'extension'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to JSON (though this is unlikely to be needed)
        DB::statement("ALTER TABLE ring_groups MODIFY COLUMN fallback_action JSON NULL");
    }
};
