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
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->string('amd_result', 20)->nullable()->after('status')
                ->comment('AMD detection result: human, voicemail, unknown');
            $table->decimal('amd_confidence', 5, 2)->nullable()->after('amd_result')
                ->comment('AMD detection confidence (0.00 - 1.00)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->dropColumn(['amd_result', 'amd_confidence']);
        });
    }
};