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
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            // Add total_duration for cumulative call duration tracking
            $table->unsignedInteger('total_duration')->default(0)->after('duration')->comment('Cumulative duration across all call attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            $table->dropColumn('total_duration');
        });
    }
};
