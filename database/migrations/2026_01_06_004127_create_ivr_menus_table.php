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
        // Drop table if it exists to handle migration conflicts
        Schema::dropIfExists('ivr_menus');

        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('audio_file_path', 500)->nullable();
            $table->text('tts_text')->nullable();
            $table->tinyInteger('max_turns')->unsigned()->default(3);
            $table->enum('failover_destination_type', ['extension', 'ring_group', 'conference_room', 'ivr_menu', 'hangup'])->default('hangup');
            $table->unsignedBigInteger('failover_destination_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_ivr_menus_org_status');
            $table->index(['organization_id', 'name'], 'idx_ivr_menus_org_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ivr_menus');
    }
};