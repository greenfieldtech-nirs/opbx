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
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extension_number', 5);
            $table->enum('type', [
                'user',
                'conference',
                'ring_group',
                'ivr',
                'ai_assistant',
                'custom_logic',
                'forward'
            ])->default('user');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('voicemail_enabled')->default(false);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'extension_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'type']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
