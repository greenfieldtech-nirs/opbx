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
 * Platform Users Smoke Test
 *
 * Seeds a large number of users and verifies that the platform users
 * endpoint returns paginated, sortable results correctly.
 */
class PlatformUserSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $platformManager;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);
    }

    private function createDemoUsers(int $count): void
    {
        User::factory()
            ->count($count)
            ->sequence(fn (Sequence $sequence) => ['email' => 'demo-user-'.$sequence->index.'@example.com'])
            ->create([
                'organization_id' => $this->organization->id,
                'role' => UserRole::OWNER,
                'status' => UserStatus::ACTIVE,
            ]);
    }

    public function test_paginated_user_list_with_100_records(): void
    {
        $this->createDemoUsers(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users');

        $response->assertOk()
            ->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonCount(25, 'data');
    }

    public function test_second_page_returns_next_set(): void
    {
        $this->createDemoUsers(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(25, 'data');
    }

    public function test_custom_per_page(): void
    {
        $this->createDemoUsers(100);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?per_page=50');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(50, 'data');
    }

    public function test_sort_by_id(): void
    {
        $this->createDemoUsers(20);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?sort_by=id&sort_direction=asc');

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->values();

        $this->assertTrue($ids->first() < $ids->last(), 'ID ascending sort failed');
    }

    public function test_sort_by_status(): void
    {
        $this->createDemoUsers(20);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?sort_by=status&sort_direction=asc');

        $response->assertOk()
            ->assertJsonCount(21, 'data');
    }

    public function test_sort_by_is_platform_manager(): void
    {
        $this->createDemoUsers(10);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?sort_by=is_platform_manager&sort_direction=desc');

        $response->assertOk();
    }

    public function test_sort_by_created_at(): void
    {
        $this->createDemoUsers(10);

        Sanctum::actingAs($this->platformManager, ['*']);

        $response = $this->getJson('/api/v1/platform/users?sort_by=created_at&sort_direction=desc');

        $response->assertOk();
    }
}
