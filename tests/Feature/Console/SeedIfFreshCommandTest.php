<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedIfFreshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_seeding_when_organizations_exist(): void
    {
        Organization::withoutGlobalScope(OrganizationScope::class)->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->artisan('db:seed-if-fresh', ['--no-interaction' => true])
            ->expectsOutputToContain('Database already seeded, skipping.')
            ->assertSuccessful();

        $this->assertSame(1, Organization::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame(0, User::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_seeds_when_database_is_empty(): void
    {
        $this->artisan('db:seed-if-fresh', ['--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(1, Organization::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame(1, User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', 'admin@example.com')
            ->count());
    }
}
