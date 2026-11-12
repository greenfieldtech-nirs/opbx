<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Scopes\OrganizationScope;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private function adminCount(): int
    {
        return User::withoutGlobalScope(OrganizationScope::class)->where('email', 'admin@example.com')->count();
    }

    public function test_admin_seeder_skips_when_installer_managed(): void
    {
        config(['app.installer_managed' => true]);
        $this->seed(AdminUserSeeder::class);
        $this->assertSame(0, $this->adminCount());
    }

    public function test_admin_seeder_still_seeds_by_default(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->assertSame(1, $this->adminCount());
    }
}
