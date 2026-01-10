<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveSessionIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sessions:remove {session_ids*} {--table=session_updates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove specific session IDs from the session_updates table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sessionIds = $this->argument('session_ids');
        $table = $this->option('table');

        $this->info("Removing session IDs from {$table} table:");
        foreach ($sessionIds as $sessionId) {
            $this->line("  - {$sessionId}");
        }

        if (!$this->confirm('Do you want to proceed with the deletion?')) {
            $this->info('Operation cancelled.');
            return;
        }

        $count = DB::table($table)
            ->whereIn('session_id', $sessionIds)
            ->delete();

        $this->info("Successfully removed {$count} records from {$table} table.");
    }
}