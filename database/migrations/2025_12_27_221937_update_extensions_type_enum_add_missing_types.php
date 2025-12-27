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
        // Update the type enum to include all extension types
        // Remove 'virtual' and 'custom_logic', add 'ring_group', 'ivr', 'ai_assistant', 'forward'
        DB::statement("ALTER TABLE extensions MODIFY COLUMN type ENUM('user','conference','ring_group','ivr','ai_assistant','forward') NOT NULL DEFAULT 'user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to the original enum values
        // Note: This will fail if there are extensions with ring_group, ivr, ai_assistant, or forward types
        DB::statement("ALTER TABLE extensions MODIFY COLUMN type ENUM('user','virtual','conference') NOT NULL DEFAULT 'user'");
    }
};
