<?php

declare(strict_types=1);

use App\Enums\UserStatus;
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
        Schema::create('conference_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('max_participants')->default(25);
            $table->string('status')->default(UserStatus::ACTIVE->value);

            // Security settings
            $table->string('pin', 20)->nullable();
            $table->boolean('pin_required')->default(false);
            $table->string('host_pin', 20)->nullable();

            // Recording settings
            $table->boolean('recording_enabled')->default(false);
            $table->boolean('recording_auto_start')->default(false);
            $table->string('recording_webhook_url')->nullable();

            // Participant settings
            $table->boolean('wait_for_host')->default(false);
            $table->boolean('mute_on_entry')->default(false);

            // Audio settings
            $table->boolean('announce_join_leave')->default(false);
            $table->boolean('music_on_hold')->default(false);

            // Talk detection settings
            $table->boolean('talk_detection_enabled')->default(false);
            $table->string('talk_detection_webhook_url')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conference_rooms');
    }
};
