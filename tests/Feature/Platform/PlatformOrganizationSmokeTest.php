<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Platform Organizations Smoke Test
 *
 * Seeds a large number of organizations and verifies that the platform
 * organizations endpoint returns paginated, sortable results correctly.
 */
class PlatformOrganizationSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $platformManager;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['status' => 'active']);

        $this->platformManager = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);
    }

    private function createDemoOrganizations(int $count): void
    {
        Organization::factory()
            ->count($count)
            ->sequence(fn (Sequence $sequence) => ['slug' => 'demo-org-'.$sequence->index])
            ->create();
    }

    public function test_paginated_organization_list_with_100_records(): void
    {
        $this->createDemoOrganizations(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations');

        $response->assertOk()
            ->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonCount(25, 'data');
    }

    public function test_second_page_returns_next_set(): void
    {
        $this->createDemoOrganizations(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(25, 'data');
    }

    public function test_custom_per_page(): void
    {
        $this->createDemoOrganizations(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?per_page=50');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(50, 'data');
    }

    public function test_sort_by_id(): void
    {
        $this->createDemoOrganizations(20);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?sort_by=id&sort_direction=asc');

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->values();

        $this->assertTrue($ids->first() < $ids->last(), 'ID ascending sort failed');
    }

    public function test_sort_by_status(): void
    {
        $this->createDemoOrganizations(20);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?sort_by=status&sort_direction=asc');

        $response->assertOk()
            ->assertJsonCount(21, 'data');
    }

    public function test_sort_by_extensions_count(): void
    {
        $this->createDemoOrganizations(10);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?sort_by=extensions_count&sort_direction=desc');

        $response->assertOk();
    }

    public function test_sort_by_dids_count(): void
    {
        $this->createDemoOrganizations(10);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/organizations?sort_by=dids_count&sort_direction=desc');

        $response->assertOk();
    }
}
