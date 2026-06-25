<?php

declare(strict_types=1);

namespace Tests\Feature\Auth0;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth0_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('user_social_identities'));
        $this->assertTrue(Schema::hasTable('organization_join_requests'));
    }

    public function test_migrations_can_be_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_25_000001_create_user_social_identities_table.php']);
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_06_25_000002_create_organization_join_requests_table.php']);

        $this->assertFalse(Schema::hasTable('user_social_identities'));
        $this->assertFalse(Schema::hasTable('organization_join_requests'));
    }
}
