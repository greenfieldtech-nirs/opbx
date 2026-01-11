<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default admin user
        $this->call(AdminUserSeeder::class);

        // Fix missing ring groups and IVR menus referenced by extensions 3001 and 3002
        $this->call(FixMissingRingGroupAndIvrMenuSeeder::class);

        // Uncomment to create additional test users
        // User::factory(10)->create();
    }
}
