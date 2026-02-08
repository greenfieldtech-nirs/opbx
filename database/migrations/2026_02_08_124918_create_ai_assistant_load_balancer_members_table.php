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
        if (! Schema::hasTable('ai_assistant_load_balancer_members')) {
            Schema::create('ai_assistant_load_balancer_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('load_balancer_id')->constrained('ai_assistant_load_balancers')->cascadeOnDelete();
                $table->foreignId('ai_assistant_id')->constrained()->cascadeOnDelete();
                $table->integer('priority')->default(0); // Lower = higher priority
                $table->integer('weight')->default(100); // 0-100 for percentage strategy
                $table->integer('position')->default(0); // For round robin order
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->index(['load_balancer_id', 'status']);
                $table->index(['load_balancer_id', 'priority']);
                $table->index(['load_balancer_id', 'position']);
                $table->unique(['load_balancer_id', 'ai_assistant_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_load_balancer_members');
    }
};
