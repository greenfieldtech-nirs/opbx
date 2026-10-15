<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Platform\PlatformHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PlatformHealthCheckCommand extends Command
{
    protected $signature = 'platform:health-check';

    protected $description = 'Check every platform component and store the results (Platform Status history)';

    public function handle(PlatformHealthService $service): int
    {
        // Written first so the scheduler probe reports the scheduled cadence, not manual "Check now" runs.
        Cache::put(PlatformHealthService::HEARTBEAT_KEY, time(), now()->addDay());

        $results = $service->runAndStore();
        $bad = array_filter($results, fn (array $r) => in_array($r['status'], ['degraded', 'down'], true));
        $this->info(count($results).' components checked, '.count($bad).' degraded or down.');

        return self::SUCCESS;
    }
}
