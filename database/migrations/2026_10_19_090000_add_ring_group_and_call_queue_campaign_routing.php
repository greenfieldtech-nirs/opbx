<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-dialer campaigns can route answered calls to a ring group or a call queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function ($table): void {
            $table->enum('routing_destination_type', ['ai_assistant', 'ai_load_balancer', 'ring_group', 'call_queue', 'hangup'])->change();
        });
    }

    public function down(): void
    {
        // The old column cannot hold the new types: those campaigns fall back to hanging up.
        DB::table('auto_dialer_campaigns')
            ->whereIn('routing_destination_type', ['ring_group', 'call_queue'])
            ->update(['routing_destination_type' => 'hangup', 'routing_destination_id' => null]);

        Schema::table('auto_dialer_campaigns', function ($table): void {
            $table->enum('routing_destination_type', ['ai_assistant', 'ai_load_balancer', 'hangup'])->change();
        });
    }
};
