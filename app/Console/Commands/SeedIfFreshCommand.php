<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedIfFreshCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-if-fresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run database seeders only on a fresh (unseeded) installation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Schema::hasTable('organizations') && DB::table('organizations')->count() > 0) {
            $this->info('Database already seeded, skipping.');

            return self::SUCCESS;
        }

        $this->info('Fresh installation detected, running seeders...');
        $this->call('db:seed', ['--force' => true, '--no-interaction' => true]);

        return self::SUCCESS;
    }
}
