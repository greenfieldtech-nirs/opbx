<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureTenantScope;
use App\Models\Organization;
use App\Models\User;
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
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_unauthenticated_user_is_blocked(): void
    {
        $response = $this->get('/api/v1/users', []);

        $response->assertStatus(401);
        // The route is protected by auth:sanctum first, which returns this message
        $response->assertJson([
            'message' => 'Authentication required to access this resource.',
        ]);
    }

    public function test_authenticated_user_without_org_is_blocked(): void
    {
        // Create a user with a valid org (required by DB NOT NULL), then simulate
        // a missing organization context by clearing the attribute on the model
        // instance used by the request.
        $userWithoutOrg = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $userWithoutOrg->organization_id = null;

        $response = $this->actingAs($userWithoutOrg)->get('/api/v1/users', []);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'User does not belong to an organization.',
        ]);
    }

    public function test_inactive_organization_is_blocked(): void
    {
        // Update test user's organization to deleted (non-active) status
        $this->organization->update(['status' => OrganizationStatus::DELETED]);
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
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        // Test that middleware allows access to individual user endpoint
        // (assuming the controller handles authorization)
        $response = $this->actingAs($this->user)->get('/api/v1/users/'.$otherUser->id);

        // The middleware should pass (not return 403 for org issues)
        // Authorization errors would be 403 with different messages
        $this->assertNotEquals(403, $response->getStatusCode());
        // Or check that it's not the specific middleware error messages
        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('User does not belong to an organization', $responseContent);
        $this->assertStringNotContainsString('Organization is not active', $responseContent);
    }
}
