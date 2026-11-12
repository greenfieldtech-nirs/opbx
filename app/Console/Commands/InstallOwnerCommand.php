<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Non-interactive first-owner creation used by the guided installer.
 * The password is read from the first line of stdin and never printed.
 *
 * Exit codes: 0 created, 2 a user already exists, 1 any other error.
 */
class InstallOwnerCommand extends Command
{
    /** Test hook: replaces reading from STDIN. */
    public static ?Closure $stdinReader = null;

    protected $signature = 'opbx:install-owner
        {--org-name= : Organization name}
        {--name= : Owner full name}
        {--email= : Owner email}';

    protected $description = 'Create the first organization and platform owner (password on stdin)';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = trim((string) $this->option('name'));
        $orgName = trim((string) $this->option('org-name'));

        if (OrganizationScope::bypass(fn () => User::query()->exists())) {
            $this->error('A user already exists; refusing to create an owner.');

            return 2;
        }

        if (! static::$stdinReader && function_exists('posix_isatty') && posix_isatty(STDIN)) {
            $this->error('Pipe the password on stdin.');

            return self::FAILURE;
        }

        $line = static::$stdinReader ? (static::$stdinReader)() : fgets(STDIN);
        $password = rtrim((string) $line, "\r\n");

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'org' => $orgName, 'password' => $password],
            [
                'email' => 'required|email',
                'name' => 'required',
                'org' => 'required',
                'password' => 'required|min:16',
            ]
        );
        if ($validator->fails()) {
            $this->error('Invalid input: '.implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $slug = Str::slug($orgName);
        if ($slug === '') {
            $this->error('Invalid input: organization name must contain letters or digits.');

            return self::FAILURE;
        }

        try {
            $created = DB::transaction(function () use ($email, $name, $orgName, $slug, $password): bool {
                // Re-check under lock so concurrent runs cannot both create an owner.
                if (OrganizationScope::bypass(fn () => User::query()->lockForUpdate()->exists())) {
                    return false;
                }

                $org = Organization::create([
                    'name' => $orgName,
                    'slug' => $slug,
                    'timezone' => 'UTC',
                    'status' => 'active',
                ]);

                (new User)->forceFill([
                    'organization_id' => $org->id,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => UserRole::OWNER,
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'is_platform_manager' => true,
                ])->save();

                return true;
            });
        } catch (\Throwable $e) {
            $this->error('Failed to create owner: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $created) {
            $this->error('A user already exists; refusing to create an owner.');

            return 2;
        }

        $this->info("Owner created: {$email}");

        return self::SUCCESS;
    }
}
