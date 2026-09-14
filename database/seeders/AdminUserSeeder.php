<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * Creates a default organization and owner user for initial setup.
     * Each entity is guarded independently so the seeder is idempotent and
     * safe to run against an already-seeded (or partially drifted) database.
     */
    public function run(): void
    {
        // Bypass tenant scope: seeders run unauthenticated and must see all rows.
        $organization = Organization::withoutGlobalScope(OrganizationScope::class)
            ->firstOrCreate(
                ['slug' => 'default-org'],
                [
                    'name' => 'Default Organization',
                    'timezone' => 'UTC',
                    'status' => 'active',
                ]
            );

        if ($organization->wasRecentlyCreated) {
            $this->command->info('Default organization created.');
        } else {
            $this->command->info('Default organization already exists, skipping...');
        }

        $adminExists = User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', 'admin@example.com')
            ->exists();

        if ($adminExists) {
            $this->command->info('Admin user already exists, skipping...');

            return;
        }

        User::create([
            'organization_id' => $organization->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->command->info('Admin user created successfully!');
        $this->command->info('Email: admin@example.com');
        $this->command->info('Password: password');
        $this->command->warn('Please change the password after first login!');
    }
}
