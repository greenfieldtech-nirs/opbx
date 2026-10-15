<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UsersIndexFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['status' => 'active']);
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER, 'name' => 'Olivia Owner', 'email' => 'owner@acme.test']);
        User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER, 'status' => UserStatus::ACTIVE, 'name' => 'Alice Agent', 'email' => 'alice@acme.test']);
        User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER, 'status' => UserStatus::INACTIVE, 'name' => 'Bob Agent', 'email' => 'bob@acme.test']);
        User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::REPORTER, 'status' => UserStatus::ACTIVE, 'name' => 'Rita Reporter', 'email' => 'reports@example.org']);
        Sanctum::actingAs($owner);
    }

    /** @return array<int, string> */
    private function names(string $query): array
    {
        $names = collect($this->getJson('/api/v1/users?'.$query)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        return $names;
    }

    public function test_role_filter(): void
    {
        $this->assertSame(['Alice Agent', 'Bob Agent'], $this->names('role=pbx_user'));
    }

    public function test_status_filter(): void
    {
        $this->assertSame(['Bob Agent'], $this->names('status=inactive'));
    }

    public function test_search_matches_name_or_email(): void
    {
        $this->assertSame(['Rita Reporter'], $this->names('search=example.org'));
        $this->assertSame(['Alice Agent', 'Bob Agent'], $this->names('search=agent'));
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['Alice Agent'], $this->names('role=pbx_user&status=active&search=ali'));
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $this->assertCount(4, $this->names('role=wizard&status=sleeping'));
    }
}
