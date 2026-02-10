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
        Schema::table('call_notification_logs', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->after('request_payload');
            $table->text('request_body')->nullable()->after('request_headers');
            $table->json('response_headers')->nullable()->after('response_body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_notification_logs', function (Blueprint $table) {
            $table->dropColumn(['request_headers', 'request_body', 'response_headers']);
        });
    }
};
