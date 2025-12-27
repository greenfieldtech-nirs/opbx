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
            // Add ring_turns column after timeout
            $table->integer('ring_turns')->default(2)->after('timeout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ring_groups', function (Blueprint $table) {
            $table->dropColumn('ring_turns');
        });
    }
};
