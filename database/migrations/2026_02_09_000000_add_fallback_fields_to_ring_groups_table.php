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
        Schema::table('ring_groups', function (Blueprint $table) {
            // Update the enum to include new fallback actions
            $table->enum('fallback_action', ['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup'])
                ->default('extension')
                ->change();

            // Add new fallback fields (check if not exists for idempotency)
            if (! Schema::hasColumn('ring_groups', 'fallback_ring_group_id')) {
                $table->foreignId('fallback_ring_group_id')->nullable()->constrained('ring_groups')->nullOnDelete();
            }
            if (! Schema::hasColumn('ring_groups', 'fallback_ivr_menu_id')) {
                $table->foreignId('fallback_ivr_menu_id')->nullable()->constrained('ivr_menus')->nullOnDelete();
            }
            if (! Schema::hasColumn('ring_groups', 'fallback_ai_assistant_id')) {
                $table->foreignId('fallback_ai_assistant_id')->nullable()->constrained('extensions')->nullOnDelete();
            }
            if (! Schema::hasColumn('ring_groups', 'fallback_ai_load_balancer_id')) {
                $table->foreignId('fallback_ai_load_balancer_id')->nullable()->constrained('ai_assistant_load_balancers')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ring_groups', function (Blueprint $table) {
            // Remove new fields (check if exists before dropping)
            if (Schema::hasColumn('ring_groups', 'fallback_ring_group_id')) {
                $table->dropForeign(['fallback_ring_group_id']);
                $table->dropColumn('fallback_ring_group_id');
            }
            if (Schema::hasColumn('ring_groups', 'fallback_ivr_menu_id')) {
                $table->dropForeign(['fallback_ivr_menu_id']);
                $table->dropColumn('fallback_ivr_menu_id');
            }
            if (Schema::hasColumn('ring_groups', 'fallback_ai_assistant_id')) {
                $table->dropForeign(['fallback_ai_assistant_id']);
                $table->dropColumn('fallback_ai_assistant_id');
            }
            if (Schema::hasColumn('ring_groups', 'fallback_ai_load_balancer_id')) {
                $table->dropForeign(['fallback_ai_load_balancer_id']);
                $table->dropColumn('fallback_ai_load_balancer_id');
            }

            // Revert enum to original values
            $table->enum('fallback_action', ['extension', 'hangup'])
                ->default('extension')
                ->change();
        });
    }
};
