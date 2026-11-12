<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\InstallOwnerCommand;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function tearDown(): void
    {
        InstallOwnerCommand::$stdinReader = null;
        parent::tearDown();
    }

    private function run_install(string $stdin, string $email = 'jane@acme.com'): \Illuminate\Testing\PendingCommand
    {
        InstallOwnerCommand::$stdinReader = fn () => $stdin."\n";

        return $this->artisan('opbx:install-owner', [
            '--org-name' => 'Acme Inc', '--name' => 'Jane Doe', '--email' => $email,
        ]);
    }

    public function test_creates_org_and_platform_owner_from_stdin_password(): void
    {
        $this->run_install(self::PASSWORD)->expectsOutput('Owner created: jane@acme.com')->assertExitCode(0);

        $org = Organization::withoutGlobalScope(OrganizationScope::class)->where('slug', 'acme-inc')->firstOrFail();
        $this->assertSame('UTC', $org->timezone);
        $user = User::withoutGlobalScope(OrganizationScope::class)->where('email', 'jane@acme.com')->firstOrFail();
        $this->assertSame($org->id, $user->organization_id);
        $this->assertSame('owner', $user->role->value);
        $this->assertTrue($user->is_platform_manager);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_refuses_when_a_user_exists(): void
    {
        $this->run_install(self::PASSWORD)->assertExitCode(0);
        $orgs = Organization::withoutGlobalScope(OrganizationScope::class)->count();

        $this->run_install(self::PASSWORD, 'other@acme.com')->assertExitCode(2);

        $this->assertSame($orgs, Organization::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_rejects_short_password(): void
    {
        $this->run_install('short')->assertExitCode(1);
        $this->assertSame(0, User::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_rejects_invalid_email(): void
    {
        $this->run_install(self::PASSWORD, 'not-an-email')->assertExitCode(1);
        $this->assertSame(0, User::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_rejects_empty_stdin(): void
    {
        $this->run_install('')->assertExitCode(1);
    }
}
