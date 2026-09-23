<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the public queues dashboard deep-link token to organizations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('public_queues_token')->nullable()->unique()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['public_queues_token']);
            $table->dropColumn('public_queues_token');
        });
    }
};
