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
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_manager_user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('target_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            $table->string('action', 100);
            $table->string('target_entity_type', 100)->nullable();
            $table->unsignedBigInteger('target_entity_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('platform_manager_user_id', 'idx_pal_manager_user');
            $table->index('target_organization_id', 'idx_pal_target_org');
            $table->index('action', 'idx_pal_action');
            $table->index('created_at', 'idx_pal_created_at');
            $table->index(
                ['target_entity_type', 'target_entity_id'],
                'idx_pal_target_entity'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
