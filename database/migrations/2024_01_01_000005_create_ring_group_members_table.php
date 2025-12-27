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
        Schema::create('ring_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ring_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(1); // 1-100, used for sequential strategy
            $table->timestamps();

            $table->index('ring_group_id');
            $table->index('extension_id');
            $table->unique(['ring_group_id', 'extension_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ring_group_members');
    }
};
