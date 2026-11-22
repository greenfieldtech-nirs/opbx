<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per time a call is handed to an AI assistant (the AI Real Time Monitor's data).
     */
    public function up(): void
    {
        Schema::create('ai_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_assistant_id')->nullable()->constrained('ai_assistants')->nullOnDelete();
            $table->foreignId('ai_assistant_load_balancer_id')->nullable()
                ->constrained('ai_assistant_load_balancers')->nullOnDelete();
            $table->string('call_id');
            $table->string('source', 20);
            $table->string('status', 20);
            $table->string('failure_reason', 50)->nullable();
            $table->boolean('failed_over')->default(false);
            $table->boolean('fell_back')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('billsec')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'started_at']);
            $table->index(['organization_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_call_attempts');
    }
};
