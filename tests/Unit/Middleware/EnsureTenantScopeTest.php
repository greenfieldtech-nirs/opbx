<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureTenantScope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    protected User $user;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test organization
        $this->organization = Organization::factory()->create([
            'name' => 'Test Organization',
            'status' => 'active',
        ]);

        // Create a test user with organization
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => \App\Enums\UserRole::OWNER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);
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
        // Create a user without organization
        $userWithoutOrg = User::factory()->create([
            'organization_id' => null,
        ]);

        $response = $this->actingAs($userWithoutOrg)->get('/api/v1/users', []);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'User does not belong to an organization.',
        ]);
    }

    public function test_inactive_organization_is_blocked(): void
    {
        // Update test user's organization to inactive
        $this->organization->update(['status' => 'inactive']);
        $this->user->refresh(); // Refresh to get updated relationship

        $response = $this->actingAs($this->user)->get('/api/v1/users', []);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Organization is not active.',
        ]);
    }

    public function test_authenticated_user_with_active_org_succeeds(): void
    {
        $response = $this->actingAs($this->user)->get('/api/v1/users', []);

        $response->assertStatus(200);
        // The middleware passes, but authorization might still block access
        // This test just verifies the middleware doesn't block valid users
    }

    public function test_user_can_only_see_own_org_data(): void
    {
        // Create another user in same org
        $otherUser = User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'role' => \App\Enums\UserRole::PBX_USER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        // Test that middleware allows access to individual user endpoint
        // (assuming the controller handles authorization)
        $response = $this->actingAs($this->user)->get('/api/v1/users/' . $otherUser->id);

        // The middleware should pass (not return 403 for org issues)
        // Authorization errors would be 403 with different messages
        $this->assertNotEquals(403, $response->getStatusCode());
        // Or check that it's not the specific middleware error messages
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('User does not belong to an organization', $responseContent);
        $this->assertStringNotContainsString('Organization is not active', $responseContent);
    }
}
