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
        Schema::create('outbound_whitelists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('destination_country');
            $table->string('destination_prefix');
            $table->string('outbound_trunk_name');
            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'destination_country']);
            $table->index(['organization_id', 'destination_prefix']);
            $table->index(['organization_id', 'outbound_trunk_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_whitelists');
    }
};