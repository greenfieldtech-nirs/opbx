<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to bulk sync extensions to Cloudonix Subscribers.
 *
 * This command syncs all USER type extensions to Cloudonix for one or all organizations.
 * Only extensions that haven't been synced yet (cloudonix_synced = false) are processed.
 */
class CloudonixSyncSubscribers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloudonix:sync-subscribers
                            {organization? : The organization ID to sync (optional, syncs all if not specified)}
                            {--force : Force sync even if already synced}
                            {--dry-run : Simulate the sync without making actual changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk sync extensions to Cloudonix Subscribers for one or all organizations';

    /**
     * Execute the console command.
     */
    public function handle(CloudonixSubscriberService $subscriberService): int
    {
        $organizationId = $this->argument('organization');
        $forceUpdate = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info('Cloudonix Subscriber Bulk Sync');
        $this->info('==============================');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No actual changes will be made');
            $this->newLine();
        }

        try {
            // Get organizations to sync
            $organizations = $this->getOrganizations($organizationId);

            if ($organizations->isEmpty()) {
                $this->error('No organizations found to sync.');

                return self::FAILURE;
            }

            $this->info("Processing {$organizations->count()} organization(s)...");
            $this->newLine();

            $totalStats = [
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];

            foreach ($organizations as $organization) {
                $this->line("Organization: {$organization->name} (ID: {$organization->id})");

                // Check if Cloudonix is configured
                $settings = $organization->cloudonixSettings;

                if (!$settings || !$settings->isConfigured()) {
                    $this->warn("  ⚠ Skipped - Cloudonix not configured");
                    $this->newLine();
                    continue;
                }

                if ($dryRun) {
                    // Simulate sync
                    $count = $organization->extensions()
                        ->where('type', 'user')
                        ->where('cloudonix_synced', false)
                        ->count();

                    $this->info("  Would sync {$count} extension(s)");
                } else {
                    // Perform actual sync
                    $stats = $subscriberService->bulkSync($organization, $forceUpdate);

                    $this->info("  ✓ Success: {$stats['success']}");

                    if ($stats['failed'] > 0) {
                        $this->warn("  ✗ Failed: {$stats['failed']}");
                    }

                    if ($stats['skipped'] > 0) {
                        $this->line("  ⊘ Skipped: {$stats['skipped']}");
                    }

                    // Aggregate stats
                    $totalStats['success'] += $stats['success'];
                    $totalStats['failed'] += $stats['failed'];
                    $totalStats['skipped'] += $stats['skipped'];
                }

                $this->newLine();
            }

            // Display summary
            if (!$dryRun) {
                $this->info('Summary');
                $this->info('=======');
                $this->info("Total Success: {$totalStats['success']}");

                if ($totalStats['failed'] > 0) {
                    $this->warn("Total Failed: {$totalStats['failed']}");
                }

                if ($totalStats['skipped'] > 0) {
                    $this->line("Total Skipped: {$totalStats['skipped']}");
                }

                Log::info('Cloudonix bulk sync completed', [
                    'command' => 'cloudonix:sync-subscribers',
                    'organization_id' => $organizationId,
                    'stats' => $totalStats,
                ]);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('An error occurred during sync: ' . $e->getMessage());

            Log::error('Cloudonix bulk sync failed', [
                'command' => 'cloudonix:sync-subscribers',
                'organization_id' => $organizationId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Get the organizations to sync.
     *
     * @param string|null $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getOrganizations(?string $organizationId): \Illuminate\Database\Eloquent\Collection
    {
        if ($organizationId !== null) {
            // Sync specific organization
            $organization = Organization::with('cloudonixSettings')->find($organizationId);

            if (!$organization) {
                return collect();
            }

            return collect([$organization]);
        }

        // Sync all organizations
        return Organization::with('cloudonixSettings')->get();
    }
}
