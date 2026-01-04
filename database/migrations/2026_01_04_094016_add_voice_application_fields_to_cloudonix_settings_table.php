<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('voice_application_id')->nullable()->after('domain_requests_api_key');
            $table->string('voice_application_uuid')->nullable()->after('voice_application_id');
            $table->string('voice_application_name')->nullable()->after('voice_application_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->dropColumn(['voice_application_id', 'voice_application_uuid', 'voice_application_name']);
        });
    }
};
