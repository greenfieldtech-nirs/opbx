<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SessionUpdate;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup stale session update records
 *
 * Marks session updates as 'deleted' if they haven't been updated
 * in a reasonable time and are still showing as active.
 */
class CleanupStaleSessionUpdates extends Command
{
    protected $signature = 'session-updates:cleanup 
                            {--hours=2 : Hours of inactivity before marking as stale}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Cleanup stale session update records that are stuck in active states';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $cutoffTime = Carbon::now()->subHours($hours);

        $this->info("Looking for stale session updates older than {$hours} hours...");
        $this->info("Cutoff time: {$cutoffTime}");

        // Find stale active records using raw query to avoid timezone issues
        $staleQuery = SessionUpdate::whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->whereNotIn('action', ['deleted', 'cdr_final_status'])
            ->whereRaw('updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours]);

        $staleCount = $staleQuery->count();

        if ($staleCount === 0) {
            $this->info('No stale session updates found.');
            return self::SUCCESS;
        }

        $this->warn("Found {$staleCount} stale session update records");

        // Show breakdown by status
        $breakdown = SessionUpdate::whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->whereNotIn('action', ['deleted', 'cdr_final_status'])
            ->whereRaw('updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours])
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $this->table(['Status', 'Count'], $breakdown->map(fn ($count, $status) => [$status, $count])->toArray());

        if ($dryRun) {
            $this->info('Dry run mode - no changes made.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Do you want to mark these records as deleted?', false)) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        // Update stale records to mark them as deleted
        $updated = SessionUpdate::whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->whereNotIn('action', ['deleted', 'cdr_final_status'])
            ->whereRaw('updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)', [$hours])
            ->update([
                'action' => 'deleted',
                'status' => 'disconnected',
                'updated_at' => Carbon::now(),
            ]);

        $this->info("Successfully marked {$updated} stale records as deleted.");

        return self::SUCCESS;
    }
}
