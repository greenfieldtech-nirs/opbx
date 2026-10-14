<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Ring Agents in Separate Calls" becomes the default for Ring All queues (owner decision, 2026-10-15):
 * switch it on for every existing Ring All queue. Admins can switch it off again per queue.
 * Running it twice changes nothing more; down() is a no-op because the earlier per-queue choice is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('call_queues')
            ->where('strategy', 'ring_all')
            ->where('conference_ring_all', false)
            ->update(['conference_ring_all' => true]);
    }

    public function down(): void {}
};
