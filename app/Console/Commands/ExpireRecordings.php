<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cdr\RecordingExpiry;
use Illuminate\Console\Command;

class ExpireRecordings extends Command
{
    protected $signature = 'recordings:expire';

    protected $description = 'Delete call recordings past RECORDING_RETENTION_MONTHS and reset their hot/warm CDRs';

    public function handle(RecordingExpiry $expiry): int
    {
        $this->info("recordings: {$expiry->expire()} deleted (retention {$expiry->retentionMonths()} months)");

        return self::SUCCESS;
    }
}
