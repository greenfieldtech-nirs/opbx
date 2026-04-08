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
        Schema::create('auto_dialer_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('auto_dialer_campaigns')->cascadeOnDelete();

            $table->string('name');
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');

            // Upload tracking
            $table->string('original_filename')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);

            $table->timestamps();

            // Unique constraint - one list per campaign
            $table->unique('campaign_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_lists');
    }
};
