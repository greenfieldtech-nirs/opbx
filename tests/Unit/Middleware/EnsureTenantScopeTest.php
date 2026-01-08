<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureTenantScope;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnsureTenantScope Middleware Tests
 *
 * Tests tenant isolation enforcement in requests
 * Ensures that users cannot access other organizations' data
 * Ensures organization context is properly validated
 */
class EnsureTenantScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user with organization
        $this->user = \App\Models\User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => \App\Enums\UserRole::OWNER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);
        $this->user->save();
    }

    public function test_unauthenticated_user_is_blocked(): void
    {
        $response = $this->get('/api/v1/users', []);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_authenticated_user_without_org_is_blocked(): void
    {
        $response = $this->actingAs($this->user)->get('/api/v1/users', []);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'User does not belong to an organization.',
        ]);
    }

    public function test_inactive_organization_is_blocked(): void
    {
        // Update test user's organization to inactive
        $this->user->organization->status = \App\Enums\OrganizationStatus::INACTIVE;
        $this->user->save();

        $response = $this->actingAs($this->user)->get('/api/v1/users', []);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Organization is not active.',
        ]);
    }

    public function test_authenticated_user_with_active_org_succeeds(): void
    {
        $response = $this->actingAs($this->user)->get('/api/v1/users', []);

        $this->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_user_can_only_see_own_org_data(): void
    {
        // Create another user in same org
        $otherUser = \App\Models\User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'role' => \App\Enums\UserRole::PBX_USER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);
        $otherUser->save();

        $response = $this->actingAs($this->user)->get('/api/v1/users/' . $otherUser->id);

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $otherUser->id,
            'name' => $otherUser->name,
            'email' => $otherUser->email,
        ]);

        // Verify own user cannot see other users
        $response = $this->actingAs($this->user)->get('/api/v1/users');

        $this->assertStatus(403);
        $response->assertJson([
            'message' => 'This action is unauthorized.',
        ]);
    }
}
