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
        Schema::create('call_tracking_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tracking_campaign_id')->unique('ct_notif_settings_campaign_unique');
            $table->string('webhook_url', 2048);
            $table->enum('auth_method', ['none', 'bearer_token', 'basic_auth'])->default('none');
            $table->text('auth_secret')->nullable();
            $table->string('auth_username', 255)->nullable();
            $table->json('enabled_events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('call_tracking_campaign_id', 'ct_notif_settings_campaign_fk')
                ->references('id')
                ->on('call_tracking_campaigns')
                ->onDelete('cascade');

            $table->index(['organization_id', 'is_active'], 'ct_notif_settings_org_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_notification_settings');
    }
};
