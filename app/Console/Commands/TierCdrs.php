<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cdr\CdrTiering;
use Illuminate\Console\Command;

class TierCdrs extends Command
{
    protected $signature = 'cdr:tier';

    protected $description = 'Move CDRs hot -> warm -> cold and delete expired cold files (see config/retention.php)';

    public function handle(CdrTiering $tiering): int
    {
        try {
            $this->info('hot -> warm: '.$tiering->hotToWarm().' rows');
            $this->info('warm -> cold: '.$tiering->warmToCold().' files');
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
