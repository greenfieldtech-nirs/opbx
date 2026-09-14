<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_cleanly_on_empty_database(): void
    {
        $this->seed(AdminUserSeeder::class);

        $organization = Organization::withoutGlobalScope(OrganizationScope::class)
            ->where('slug', 'default-org')
            ->sole();
        $this->assertSame('Default Organization', $organization->name);
        $this->assertSame('UTC', $organization->timezone);
        $this->assertSame('active', $organization->status);

        $admin = User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', 'admin@example.com')
            ->sole();
        $this->assertSame($organization->id, $admin->organization_id);
        $this->assertSame('Admin User', $admin->name);
    }

    public function test_running_seeder_twice_is_a_no_op(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, Organization::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame(1, User::withoutGlobalScope(OrganizationScope::class)->count());
    }

    public function test_creates_admin_against_existing_renamed_org_without_duplicate_slug_error(): void
    {
        $organization = Organization::withoutGlobalScope(OrganizationScope::class)->create([
            'name' => 'Acme Corp',
            'slug' => 'default-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, Organization::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame('Acme Corp', $organization->fresh()->name);

        $admin = User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', 'admin@example.com')
            ->sole();
        $this->assertSame($organization->id, $admin->organization_id);
    }

    public function test_existing_org_and_admin_are_left_untouched(): void
    {
        $organization = Organization::withoutGlobalScope(OrganizationScope::class)->create([
            'name' => 'Acme Corp',
            'slug' => 'default-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Existing Admin',
            'email' => 'admin@example.com',
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, Organization::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame(1, User::withoutGlobalScope(OrganizationScope::class)->count());
        $this->assertSame('Acme Corp', $organization->fresh()->name);
        $this->assertSame('Existing Admin', $admin->fresh()->name);
    }
}
