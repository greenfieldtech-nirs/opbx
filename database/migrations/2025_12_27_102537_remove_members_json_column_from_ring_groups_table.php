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
        Schema::table('ring_groups', function (Blueprint $table) {
            // Remove the members JSON column - members should be in ring_group_members table
            $table->dropColumn('members');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ring_groups', function (Blueprint $table) {
            // Add back the members column if rollback is needed
            $table->json('members')->nullable();
        });
    }
};
