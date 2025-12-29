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
        Schema::create('session_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Session identification
            $table->bigInteger('session_id');
            $table->string('event_id', 36);

            // Cloudonix data
            $table->integer('domain_id');
            $table->string('domain');
            $table->integer('subscriber_id');
            $table->integer('outgoing_subscriber_id')->nullable();

            // Call details
            $table->string('caller_id', 50);
            $table->string('destination', 50);
            $table->enum('direction', ['incoming', 'outgoing']);
            $table->string('status', 50);

            // Timestamps
            $table->timestamp('session_created_at');
            $table->timestamp('session_modified_at');
            $table->bigInteger('call_start_time')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->bigInteger('call_answer_time')->nullable();
            $table->timestamp('answer_time')->nullable();

            // Configuration
            $table->integer('time_limit')->nullable();
            $table->string('vapp_server', 45)->nullable();

            // Event data
            $table->string('action', 50);
            $table->string('reason', 100);
            $table->text('last_error')->nullable();

            // Raw data storage
            $table->json('call_ids');
            $table->json('profile');

            // Metadata
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->unique(['organization_id', 'session_id', 'event_id'], 'unique_session_event');
            $table->index(['organization_id', 'session_id'], 'idx_organization_session');
            $table->index('event_id', 'idx_event_id');
            $table->index('session_created_at', 'idx_created_at');
            $table->index('status', 'idx_status');
            $table->index('direction', 'idx_direction');
            $table->index(['caller_id', 'destination'], 'idx_caller_destination');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_updates');
    }
};