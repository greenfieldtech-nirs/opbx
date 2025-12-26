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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('call_id')->unique();
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->foreignId('did_id')->nullable()->constrained('did_numbers')->nullOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ring_group_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', [
                'initiated',
                'ringing',
                'answered',
                'completed',
                'busy',
                'no_answer',
                'failed'
            ])->default('initiated');
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration')->nullable();
            $table->string('recording_url')->nullable();
            $table->json('cloudonix_cdr')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index('call_id');
            $table->index(['from_number', 'created_at']);
            $table->index(['to_number', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
