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
        Schema::create('inbound_blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');

            // Blacklist entry type
            $table->enum('match_type', ['exact', 'prefix', 'wildcard'])->default('exact');
            $table->string('caller_id_pattern', 50); // E.164 number or pattern
            $table->string('description', 255)->nullable();

            // Scope: specific DID or organization-wide
            $table->foreignId('did_number_id')->nullable()->constrained('did_numbers')->onDelete('cascade');
            $table->boolean('is_global')->default(false); // Apply to all DIDs in org

            // Rejection strategy
            $table->enum('rejection_strategy', ['drop', 'reject', 'torment'])->default('drop');

            // Torment-specific: conference room configuration
            $table->string('torment_room_prefix', 20)->nullable();
            $table->integer('torment_music_timeout')->default(300); // 5 minutes

            // Metadata
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('expires_at')->nullable(); // Temporary blacklists
            $table->integer('blocked_count')->default(0); // Statistics

            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'caller_id_pattern']);
            $table->index(['organization_id', 'did_number_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'is_global', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_blacklists');
    }
};
