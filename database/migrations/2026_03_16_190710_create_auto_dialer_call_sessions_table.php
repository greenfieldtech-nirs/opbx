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
        Schema::create('auto_dialer_call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('auto_dialer_campaigns')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('auto_dialer_destinations')->cascadeOnDelete();

            // Cloudonix Session Data
            $table->string('session_token', 255);
            $table->string('call_id', 255)->nullable();

            // Call State
            $table->enum('status', ['initiated', 'ringing', 'answered', 'completed', 'failed'])->default('initiated');

            // Timestamps
            $table->timestamp('initiated_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // AMD Results
            $table->enum('amd_result', ['human', 'voicemail', 'unknown'])->nullable();
            $table->decimal('amd_confidence', 5, 2)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('session_token', 'adcs_session_token_idx');
            $table->index(['campaign_id', 'status'], 'adcs_campaign_status_idx');
            $table->index('destination_id', 'adcs_destination_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_call_sessions');
    }
};
