<?php

declare(strict_types=1);

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
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            // Add full schedule JSON column to support multiple time ranges per day
            // This matches the Business Hours schedule format
            $table->json('schedule')->nullable()->after('timezone');

            // Make start_time and end_time nullable since schedule will handle it
            // Keep them for backward compatibility
            $table->unsignedTinyInteger('start_time')->nullable()->change();
            $table->unsignedTinyInteger('end_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn('schedule');
            $table->unsignedTinyInteger('start_time')->nullable(false)->change();
            $table->unsignedTinyInteger('end_time')->nullable(false)->change();
        });
    }
};
