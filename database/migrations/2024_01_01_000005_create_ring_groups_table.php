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
        if (!Schema::hasTable('ring_groups')) {
            Schema::create('ring_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->enum('strategy', ['simultaneous', 'round_robin', 'sequential'])->default('simultaneous');
                $table->integer('timeout')->default(30); // 5-300 seconds
                $table->integer('ring_turns')->default(1); // 1-9 complete cycles
                $table->enum('fallback_action', ['extension', 'hangup'])->default('extension');
                $table->foreignId('fallback_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();

                $table->index(['organization_id', 'status']);
                $table->unique(['organization_id', 'name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ring_groups');
    }
};
