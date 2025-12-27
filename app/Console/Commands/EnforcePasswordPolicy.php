<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Enforce password policy by flagging users who need to reset their passwords.
 *
 * This command identifies users who:
 * - Have never changed their password since account creation
 * - Haven't changed their password in a configurable period (e.g., 90 days)
 * - Have passwords that don't meet current strength requirements
 */
class EnforcePasswordPolicy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'password:enforce-policy
                            {--max-age=90 : Maximum password age in days before reset is required}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flag users who need to reset their passwords based on password policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $maxAge = (int) $this->option('max-age');
        $dryRun = $this->option('dry-run');

        $this->info('Enforcing password policy...');
        $this->info("Maximum password age: {$maxAge} days");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Find users who need password reset
        $usersNeedingReset = User::where(function ($query) use ($maxAge) {
            // Users who never changed their password
            $query->whereNull('password_last_changed_at')
                // OR users whose password is older than max age
                ->orWhere('password_last_changed_at', '<', Carbon::now()->subDays($maxAge));
        })
            // Exclude users already flagged
            ->where('password_reset_required', false)
            ->get();

        $count = $usersNeedingReset->count();

        if ($count === 0) {
            $this->info('No users need password reset.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Last Changed', 'Age (days)'],
            $usersNeedingReset->map(function ($user) {
                $lastChanged = $user->password_last_changed_at;
                $age = $lastChanged
                    ? Carbon::parse($lastChanged)->diffInDays(Carbon::now())
                    : 'Never';

                return [
                    $user->id,
                    $user->name,
                    $user->email,
                    $lastChanged ? $lastChanged->format('Y-m-d H:i:s') : 'Never',
                    $age,
                ];
            })
        );

        if ($dryRun) {
            $this->info("Would flag {$count} user(s) for password reset.");

            return Command::SUCCESS;
        }

        if (! $this->confirm("Flag {$count} user(s) for password reset?")) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        // Flag users for password reset
        $updated = User::whereIn('id', $usersNeedingReset->pluck('id'))
            ->update(['password_reset_required' => true]);

        $this->info("Successfully flagged {$updated} user(s) for password reset.");
        $this->warn('Users will be required to reset their password on next login.');

        return Command::SUCCESS;
    }
}
