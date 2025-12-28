<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * Creates a default organization and owner user for initial setup.
     */
    public function run(): void
    {
        // Check if admin user already exists
        if (User::where('email', 'admin@example.com')->exists()) {
            $this->command->info('Admin user already exists, skipping...');
            return;
        }

        // Create default organization
        $organization = Organization::firstOrCreate(
            ['name' => 'Default Organization'],
            [
                'slug' => 'default-org',
                'timezone' => 'UTC',
                'status' => 'active',
            ]
        );

        // Create owner user
        $user = User::create([
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
