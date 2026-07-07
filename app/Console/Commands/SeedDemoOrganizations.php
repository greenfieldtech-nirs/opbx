<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * Seed demo organizations for UI testing.
 */
class SeedDemoOrganizations extends Command
{
    protected $signature = 'opbx:seed-demo-organizations {count=100 : Number of organizations to create}';

    protected $description = 'Create demo organizations for UI smoke testing';

    public function handle(): int
    {
        $count = (int) $this->argument('count');

        $this->info("Creating {$count} demo organizations...");

        Organization::factory()
            ->count($count)
            ->sequence(fn (Sequence $sequence) => ['slug' => 'demo-org-'.(time() + $sequence->index)])
            ->create();

        $total = Organization::count();

        $this->info("✓ Created {$count} demo organizations. Total organizations: {$total}");

        return self::SUCCESS;
    }
}
