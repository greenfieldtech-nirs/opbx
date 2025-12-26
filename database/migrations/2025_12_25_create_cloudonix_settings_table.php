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
        Schema::create('cloudonix_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('domain_uuid')->nullable();
            $table->text('domain_api_key')->nullable(); // Encrypted - using text for encrypted data
            $table->text('domain_requests_api_key')->nullable(); // Encrypted - using text for encrypted data
            $table->integer('no_answer_timeout')->default(60); // 5-120 seconds
            $table->enum('recording_format', ['wav', 'mp3'])->default('mp3');
            $table->timestamps();

            $table->unique('organization_id'); // One setting per organization
            $table->index('domain_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cloudonix_settings');
    }
};
