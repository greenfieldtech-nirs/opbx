<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Create Platform Manager Command
 *
 * Interactive command to create a new platform manager.
 * Can either promote an existing user or create a new user and organization.
 */
class CreatePlatformManager extends Command
{
    protected $signature = 'opbx:create-platform-manager';

    protected $description = 'Create a new platform manager (interactive)';

    public function handle(): int
    {
        $this->info('=== Create Platform Manager ===');
        $this->newLine();

        // Step 1: Get email address
        $email = $this->ask('Enter email address for the platform manager');

        // Validate email format
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            $this->error('Invalid email address: '.$validator->errors()->first('email'));

            return self::FAILURE;
        }

        // Step 2: Check if user exists (bypass OrganizationScope for lookup)
        $user = OrganizationScope::bypass(function () use ($email) {
            return User::where('email', $email)->first();
        });

        if ($user) {
            $this->info("User found: {$user->name} (ID: {$user->id})");
            $this->info("Current role: {$user->role->value}");
            $this->info('Is platform manager: '.($user->is_platform_manager ? 'Yes' : 'No'));
            $this->newLine();

            if ($user->is_platform_manager) {
                $this->warn('This user is already a platform manager.');

                return self::SUCCESS;
            }

            if (! $this->confirm('Do you want to set this user as a platform manager?', true)) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }

            // Set platform manager flag
            $user->is_platform_manager = true;
            $user->save();

            $this->info("✓ User '{$user->name}' is now a platform manager.");

            return self::SUCCESS;
        }

        // Step 3: User does not exist - create new user and organization
        $this->info('User not found. Creating new platform manager...');
        $this->newLine();

        $name = $this->ask('Enter full name');
        if (empty($name)) {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $password = $this->secret('Enter password (min 8 characters)');
        $passwordConfirmation = $this->secret('Confirm password');

        if ($password !== $passwordConfirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $organizationName = $this->ask('Enter organization name');
        if (empty($organizationName)) {
            $this->error('Organization name is required.');

            return self::FAILURE;
        }

        // Step 4: Check if organization exists or create new one
        $organization = OrganizationScope::bypass(function () use ($organizationName) {
            return Organization::where('name', $organizationName)->first();
        });

        if ($organization) {
            $this->info("Using existing organization: {$organization->name} (ID: {$organization->id})");
        } else {
            $this->info("Creating new organization: {$organizationName}");
            $organization = Organization::create([
                'name' => $organizationName,
                'slug' => $this->generateSlug($organizationName),
                'status' => 'active',
                'timezone' => config('app.timezone', 'UTC'),
            ]);
            $this->info("✓ Organization created (ID: {$organization->id})");
        }

        $this->newLine();

        // Step 5: Create the user
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::OWNER,
            'status' => 'active',
            'is_platform_manager' => true,
        ]);

        $this->info("✓ User created (ID: {$user->id})");
        $this->info('✓ User is now a platform manager');
        $this->newLine();

        // Summary
        $this->info('=== Summary ===');
        $this->info("Name: {$user->name}");
        $this->info("Email: {$user->email}");
        $this->info("Organization: {$organization->name}");
        $this->info('Platform Manager: Yes');
        $this->newLine();

        $this->info('Platform manager created successfully!');

        return self::SUCCESS;
    }

    /**
     * Generate a slug from organization name.
     */
    private function generateSlug(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $base = trim($base, '-');
        $slug = $base;
        $counter = 1;

        // Ensure uniqueness
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
