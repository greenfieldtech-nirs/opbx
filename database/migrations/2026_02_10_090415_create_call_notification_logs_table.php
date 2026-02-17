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
        Schema::create('call_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('call_session_token', 255);
            $table->char('event_id', 36);
            $table->string('event_type', 50);
            $table->string('status', 50);
            $table->string('webhook_url', 2048);
            $table->json('request_payload');
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->index('call_session_token');
            $table->index(['organization_id', 'created_at']);
            $table->index('event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_notification_logs');
    }
};
