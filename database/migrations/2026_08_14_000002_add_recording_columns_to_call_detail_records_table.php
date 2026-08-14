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
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->enum('recording_status', ['none', 'pending', 'available', 'failed'])
                ->default('none')
                ->after('status');
            $table->string('recording_source_url')->nullable()->after('recording_status');
            $table->string('recording_stored_path')->nullable()->after('recording_source_url');
            $table->string('recording_mime_type')->nullable()->after('recording_stored_path');
            $table->integer('recording_duration')->nullable()->after('recording_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->dropColumn([
                'recording_status',
                'recording_source_url',
                'recording_stored_path',
                'recording_mime_type',
                'recording_duration',
            ]);
        });
    }
};
