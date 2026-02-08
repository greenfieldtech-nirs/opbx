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
        if (! Schema::hasTable('ai_assistant_load_balancers')) {
            Schema::create('ai_assistant_load_balancers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->enum('strategy', ['round_robin', 'priority', 'percentage'])->default('round_robin');
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->enum('fallback_action', ['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'hangup'])->default('hangup');
                $table->foreignId('fallback_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
                $table->foreignId('fallback_ring_group_id')->nullable()->constrained('ring_groups')->nullOnDelete();
                $table->foreignId('fallback_ivr_menu_id')->nullable()->constrained('ivr_menus')->nullOnDelete();
                $table->foreignId('fallback_ai_assistant_id')->nullable()->constrained('ai_assistants')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['organization_id', 'status']);
                $table->index(['organization_id', 'strategy']);
                $table->unique(['organization_id', 'name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_load_balancers');
    }
};
