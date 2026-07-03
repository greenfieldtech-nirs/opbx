<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Reset a user's password.
 *
 * This command allows administrators to reset a user's password directly.
 */
class ResetUserPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:reset-password
                            {email : Email address of the user}
                            {password : New password to set}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset a user\'s password';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        $force = $this->option('force');

        // Find the user
        $user = OrganizationScope::bypass(fn () => User::where('email', $email)->first());

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return Command::FAILURE;
        }

        // Show user info
        $this->info('User found:');
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Status'],
            [[
                $user->id,
                $user->name,
                $user->email,
                $user->role->value,
                $user->status->value,
            ]]
        );

        // Confirm if not forced
        if (! $force) {
            if (! $this->confirm("Reset password for user '{$user->name}' ({$user->email})?")) {
                $this->info('Operation cancelled.');

                return Command::SUCCESS;
            }
        }

        // Reset the password
        $user->password = Hash::make($password);
        $user->password_last_changed_at = now();
        $user->password_reset_required = false;
        $user->save();

        $this->info("Password successfully reset for user '{$user->name}' ({$user->email}).");

        return Command::SUCCESS;
    }
}
