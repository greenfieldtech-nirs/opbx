<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;

/**
 * Set Platform Manager Command
 *
 * Sets the platform manager flag on an existing user.
 */
class SetPlatformManager extends Command
{
    protected $signature = 'opbx:set-platform-manager {email}';

    protected $description = 'Set a user as a platform manager by email';

    public function handle(): int
    {
        $email = $this->argument('email');

        // Find user by email (bypass OrganizationScope)
        $user = OrganizationScope::bypass(function () use ($email) {
            return User::where('email', $email)->first();
        });

        if (! $user) {
            $this->error("User not found: {$email}");

            return self::FAILURE;
        }

        if ($user->is_platform_manager) {
            $this->info("User '{$user->name}' is already a platform manager.");

            return self::SUCCESS;
        }

        // Set platform manager flag
        $user->is_platform_manager = true;
        $user->save();

        $this->info("✓ User '{$user->name}' ({$user->email}) is now a platform manager.");

        return self::SUCCESS;
    }
}
