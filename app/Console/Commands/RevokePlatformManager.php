<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;

/**
 * Revoke Platform Manager Command
 *
 * Revokes the platform manager flag from a user and invalidates all their tokens.
 */
class RevokePlatformManager extends Command
{
    protected $signature = 'opbx:revoke-platform-manager {email}';

    protected $description = 'Revoke platform manager status from a user by email';

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

        if (! $user->is_platform_manager) {
            $this->info("User '{$user->name}' is not a platform manager.");

            return self::SUCCESS;
        }

        // Count total platform managers (bypass scope)
        $pmCount = OrganizationScope::bypass(function () {
            return User::where('is_platform_manager', true)->count();
        });

        if ($pmCount <= 1) {
            $this->error('Cannot revoke the last platform manager.');

            return self::FAILURE;
        }

        // Revoke platform manager flag
        $user->is_platform_manager = false;
        $user->save();

        // Revoke all Sanctum tokens
        $user->revokeAllTokens();

        $this->info("✓ Platform manager status revoked for '{$user->name}' ({$user->email}).");
        $this->info('✓ All authentication tokens have been invalidated. User must re-authenticate.');

        return self::SUCCESS;
    }
}
