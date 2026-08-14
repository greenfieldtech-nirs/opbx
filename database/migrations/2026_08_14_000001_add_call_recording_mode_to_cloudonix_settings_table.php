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
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->enum('call_recording_mode', [
                'disabled',
                'inbound',
                'outbound',
                'internal',
                'inbound_outbound',
                'all',
            ])->default('disabled')->after('no_answer_timeout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->dropColumn('call_recording_mode');
        });
    }
};
