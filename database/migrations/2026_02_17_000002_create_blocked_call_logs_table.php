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
        Schema::create('blocked_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('inbound_blacklist_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('did_number_id')->nullable()->constrained()->onDelete('set null');

            // Call details
            $table->string('caller_id', 50);
            $table->string('called_number', 50);
            $table->string('call_sid', 100)->nullable();
            $table->string('session_id', 50)->nullable();

            // Rejection details
            $table->enum('rejection_strategy', ['drop', 'reject', 'torment']);
            $table->string('torment_room_id', 100)->nullable();
            $table->integer('torment_duration')->nullable(); // How long they stayed

            // Request metadata
            $table->json('webhook_payload')->nullable();
            $table->ipAddress('source_ip')->nullable();

            $table->timestamp('blocked_at');

            // Indexes
            $table->index(['organization_id', 'blocked_at']);
            $table->index(['organization_id', 'caller_id']);
            $table->index(['organization_id', 'did_number_id', 'blocked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_call_logs');
    }
};
