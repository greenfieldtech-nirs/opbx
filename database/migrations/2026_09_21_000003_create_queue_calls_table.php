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
        Schema::create('queue_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->string('call_id');
            $table->string('session_token')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('agent_user_id')->nullable();
            $table->unsignedInteger('waiting_seconds')->nullable();
            $table->unsignedInteger('handling_seconds')->nullable();
            $table->enum('disposition', ['answered', 'abandoned', 'overflow'])->nullable();
            $table->timestamps();

            $table->unique(['call_queue_id', 'call_id']);
            $table->index(['call_queue_id', 'entered_at']);
            $table->index(['organization_id', 'entered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_calls');
    }
};
