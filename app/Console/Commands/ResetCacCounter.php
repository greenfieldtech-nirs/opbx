<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Reset the CAC (Concurrent Active Calls) counter for a campaign.
 *
 * This is useful when the CAC counter gets stuck due to missed CDR
 * webhooks or other issues, preventing new calls from being initiated.
 */
class ResetCacCounter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:reset-cac {campaign : Campaign ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the CAC counter for a campaign';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $campaignId = $this->argument('campaign');

        try {
            $redis = Redis::connection('dialer');
            $key = "dialer:cac:{$campaignId}:active";
            $currentValue = $redis->get($key);

            $redis->set($key, 0);

            $this->info("CAC counter reset for campaign {$campaignId}");
            $this->line("  Previous value: " . ($currentValue ?? 'not set'));
            $this->line("  New value: 0");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to reset CAC counter: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
