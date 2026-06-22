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
        Schema::create('call_tracking_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tracking_campaign_id');
            $table->string('call_id', 255)->nullable();
            $table->string('event_id', 255)->index('ct_notif_logs_event_id_idx');
            $table->string('event_type', 100);
            $table->string('webhook_url', 2048);
            $table->json('request_payload');
            $table->json('request_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->boolean('is_success')->default(false);
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->string('error_message', 1024)->nullable();
            $table->timestamps();

            $table->foreign('call_tracking_campaign_id', 'ct_notif_logs_campaign_fk')
                ->references('id')
                ->on('call_tracking_campaigns')
                ->onDelete('cascade');

            $table->index(['organization_id', 'call_tracking_campaign_id', 'created_at'], 'ct_notif_logs_org_campaign_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_notification_logs');
    }
};
