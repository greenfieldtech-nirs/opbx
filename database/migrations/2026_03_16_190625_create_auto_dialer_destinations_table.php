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
        Schema::create('auto_dialer_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('auto_dialer_lists')->cascadeOnDelete();

            // Destination Info
            $table->string('phone_number', 50); // E.164 format
            $table->string('description', 255)->nullable();

            // Status Tracking
            $table->enum('status', ['pending', 'dialing', 'connected', 'failed', 'completed', 'invalid'])->default('pending');
            $table->unsignedTinyInteger('dial_attempts')->default(0);

            // Cloudonix Session Tracking
            $table->string('last_session_token', 255)->nullable();
            $table->string('last_call_id', 255)->nullable();

            // CDR Data (denormalized for performance)
            $table->timestamp('last_dialed_at')->nullable();
            $table->string('last_disposition', 50)->nullable();
            $table->unsignedInteger('duration')->default(0); // seconds
            $table->unsignedInteger('billsec')->default(0); // seconds

            // Foreign key to CDR (optional, for deep linking)
            $table->foreignId('last_cdr_id')->nullable();

            // Error tracking
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['list_id', 'status'], 'add_list_status_idx');
            $table->index(['organization_id', 'phone_number'], 'add_org_phone_idx');
            $table->index('last_session_token', 'add_session_token_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_destinations');
    }
};
