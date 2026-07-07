<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * Seed demo users for UI testing.
 */
class SeedDemoUsers extends Command
{
    protected $signature = 'opbx:seed-demo-users {count=100 : Number of users to create}';

    protected $description = 'Create demo users for UI smoke testing';

    public function handle(): int
    {
        $count = (int) $this->argument('count');

        $organizationIds = Organization::pluck('id')->all();

        if ($organizationIds === []) {
            $this->error('No organizations found. Run opbx:seed-demo-organizations first.');

            return self::FAILURE;
        }

        $this->info("Creating {$count} demo users...");

        User::factory()
            ->count($count)
            ->sequence(fn (Sequence $sequence) => ['email' => 'demo-user-'.(time() + $sequence->index).'@example.com'])
            ->create([
                'organization_id' => fn () => $organizationIds[array_rand($organizationIds)],
                'role' => UserRole::OWNER,
                'status' => UserStatus::ACTIVE,
            ]);

        $total = User::count();

        $this->info("✓ Created {$count} demo users. Total users: {$total}");

        return self::SUCCESS;
    }
}
