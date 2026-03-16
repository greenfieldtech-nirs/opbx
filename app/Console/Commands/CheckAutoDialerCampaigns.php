<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AutoDialer\CampaignLifecycleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Check and auto-start auto-dialer campaigns that are due.
 *
 * This command should be scheduled to run every minute.
 */
class CheckAutoDialerCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto-dialer:check-campaigns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and auto-start auto-dialer campaigns that are due';

    /**
     * Execute the console command.
     */
    public function handle(CampaignLifecycleManager $lifecycleManager): int
    {
        $this->info('Checking auto-dialer campaigns for auto-start...');

        try {
            $lifecycleManager->checkAndAutoStart();

            $this->info('Campaign check completed successfully.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to check campaigns: '.$e->getMessage());

            Log::error('Auto-dialer campaign check failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
