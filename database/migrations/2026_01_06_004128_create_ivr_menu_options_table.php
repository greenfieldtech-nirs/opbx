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
        // Create options table only if ivr_menus table exists (child after parent)
        Schema::create('ivr_menu_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ivr_menu_id')->constrained()->cascadeOnDelete();
            $table->string('input_digits', 10);
            $table->string('description', 255)->nullable();
            $table->enum('destination_type', ['extension', 'ring_group', 'conference_room', 'ivr_menu']);
            $table->unsignedBigInteger('destination_id');
            $table->tinyInteger('priority')->unsigned()->default(1);
            $table->timestamps();

            $table->unique(['ivr_menu_id', 'input_digits'], 'unique_menu_digits');
            $table->index(['ivr_menu_id', 'priority'], 'idx_ivr_menu_options_menu_priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ivr_menu_options');
    }
};