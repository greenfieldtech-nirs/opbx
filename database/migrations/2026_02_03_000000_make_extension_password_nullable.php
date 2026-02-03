<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            // Make password nullable - only USER extensions need passwords
            // Other types (Conference, IVR, AI Assistant, Ring Group, Forward) don't need SIP authentication
            $table->string('password', 32)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            // Revert to non-nullable (will fail if null values exist)
            $table->string('password', 32)->nullable(false)->change();
        });
    }
};
