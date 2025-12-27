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
            // Add fallback_extension_id column after fallback_action
            $table->foreignId('fallback_extension_id')
                ->nullable()
                ->after('fallback_action')
                ->constrained('extensions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ring_groups', function (Blueprint $table) {
            $table->dropForeign(['fallback_extension_id']);
            $table->dropColumn('fallback_extension_id');
        });
    }
};
