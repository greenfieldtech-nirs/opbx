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
        Schema::create('call_notifications_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('webhook_url', 2048);
            $table->enum('auth_method', ['hmac_sha256', 'bearer_token', 'basic_auth', 'none'])->default('hmac_sha256');
            $table->string('auth_secret', 512)->nullable();
            $table->string('auth_username', 255)->nullable();
            $table->unsignedTinyInteger('retry_attempts')->default(3);
            $table->unsignedSmallInteger('retry_backoff_seconds')->default(60);
            $table->unsignedSmallInteger('request_timeout_seconds')->default(30);
            $table->json('enabled_events');
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('organization_id');
            $table->unique('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_notifications_settings');
    }
};
