<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformAuditLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Cleanup Platform Audit Logs Command
 *
 * Removes audit log entries older than the retention period (default: 14 days).
 */
class CleanupPlatformAuditLogs extends Command
{
    protected $signature = 'opbx:cleanup-audit-logs 
                            {--days=14 : Number of days to retain (default: 14)} 
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Clean up platform audit logs older than the retention period (default: 14 days)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $cutoffDate = Carbon::now()->subDays($days);

        $query = PlatformAuditLog::where('created_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No audit logs older than {$days} days found.");

            return self::SUCCESS;
        }

        $this->info("Found {$count} audit log entries older than {$days} days (before {$cutoffDate->toDateTimeString()}).");

        if ($dryRun) {
            $this->warn('Dry-run mode: No records will be deleted.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Do you want to delete these records?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Successfully deleted {$deleted} audit log entries.");

        return self::SUCCESS;
    }
}
