<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add standalone index on session_id column for faster lookups.
     */
    public function up(): void
    {
        Schema::table('session_updates', function (Blueprint $table) {
            // Add index on session_id for faster queries
            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_updates', function (Blueprint $table) {
            // Drop the session_id index
            $table->dropIndex(['session_id']);
        });
    }
};
