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
        Schema::create('did_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 20)->unique();
            $table->string('friendly_name')->nullable();
            $table->enum('routing_type', [
                'extension',
                'ring_group',
                'business_hours',
                'ivr',
                'voicemail'
            ])->default('extension');
            $table->json('routing_config')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('cloudonix_config')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('did_numbers');
    }
};
