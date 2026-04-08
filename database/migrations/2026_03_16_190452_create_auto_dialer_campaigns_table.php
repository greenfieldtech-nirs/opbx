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
        Schema::create('auto_dialer_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Basic Info
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'paused', 'completed', 'archived'])->default('draft');
            $table->boolean('auto_start')->default(false);

            // Routing Configuration
            $table->enum('routing_destination_type', ['ai_assistant', 'ai_load_balancer', 'hangup']);
            $table->foreignId('routing_destination_id')->nullable()->constrained('ai_assistants')->nullOnDelete();

            // Cloudonix API Parameters
            $table->unsignedSmallInteger('dial_timeout')->default(60); // 1-300 seconds
            $table->enum('destination_connect', ['connected', 'immediately'])->default('connected');
            $table->string('caller_id'); // Selected from DID numbers

            // Dialing Guidelines
            $table->unsignedTinyInteger('max_dial_attempts')->default(1); // 1-5
            $table->unsignedTinyInteger('calls_per_second')->default(1); // 1-5

            // Scheduling
            $table->json('days_active'); // ['monday', 'tuesday', ...]
            $table->unsignedTinyInteger('start_time'); // 0-23
            $table->unsignedTinyInteger('end_time'); // 0-23 (must be > start_time)
            $table->date('start_date');
            $table->date('end_date');
            $table->string('timezone')->default('UTC');

            // Optional Parameters
            $table->unsignedMediumInteger('time_limit')->default(3600); // 30-14400 seconds
            $table->boolean('record_calls')->default(false);

            // Answering Machine Detection
            $table->boolean('amd_enabled')->default(false);
            $table->enum('amd_mode', ['Enabled', 'DetectMessageEnd'])->nullable();
            $table->unsignedSmallInteger('amd_timeout')->default(30); // 5-120 seconds
            $table->unsignedSmallInteger('amd_speech_threshold')->default(1500); // 500-5000 milliseconds
            $table->unsignedSmallInteger('amd_speech_end_threshold')->default(2500); // 500-5000 milliseconds
            $table->unsignedSmallInteger('amd_silence_timeout')->default(3500); // 500-10000 milliseconds

            // Statistics (cached)
            $table->unsignedInteger('total_destinations')->default(0);
            $table->unsignedInteger('completed_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->unsignedInteger('pending_calls')->default(0);

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'status'], 'adc_org_status_idx');
            $table->index(['organization_id', 'auto_start', 'status'], 'adc_org_auto_start_idx');
            $table->index(['start_date', 'end_date'], 'adc_dates_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_campaigns');
    }
};
