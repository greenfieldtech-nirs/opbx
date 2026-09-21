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
        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('strategy', ['ring_all', 'round_robin', 'least_talk_time', 'fewest_calls'])->default('ring_all');
            $table->unsignedSmallInteger('agent_ring_timeout')->default(20);
            $table->unsignedSmallInteger('max_wait_seconds')->default(300);
            $table->unsignedSmallInteger('wrap_up_seconds')->default(15);
            $table->foreignId('moh_recording_id')->nullable()->constrained('recordings')->nullOnDelete();
            $table->enum('fallback_action', ['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup'])->default('hangup');
            $table->foreignId('fallback_extension_id')->nullable();
            $table->foreignId('fallback_ring_group_id')->nullable();
            $table->foreignId('fallback_ivr_menu_id')->nullable();
            $table->foreignId('fallback_ai_assistant_id')->nullable();
            $table->foreignId('fallback_ai_load_balancer_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_queues');
    }
};
