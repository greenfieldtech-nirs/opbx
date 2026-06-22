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
        Schema::create('call_tracking_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('destination_type', [
                'forward',
                'extension',
                'ring_group',
                'business_hours',
                'conference_room',
                'ivr_menu',
                'ai_assistant',
                'ai_load_balancer',
            ])->default('forward');
            $table->json('destination_config');
            $table->json('conversion_rule');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'source', 'medium']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_campaigns');
    }
};
