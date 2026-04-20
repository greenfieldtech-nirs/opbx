<?php

declare(strict_types=1);

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
        // The AMD worker sends 'voicemail' not 'machine' as the result.
        // Update the enum to match actual values from the worker.
        DB::statement("ALTER TABLE auto_dialer_call_sessions MODIFY COLUMN amd_result ENUM('human', 'voicemail', 'unknown') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE auto_dialer_call_sessions MODIFY COLUMN amd_result ENUM('human', 'machine', 'unknown') NULL");
    }
};