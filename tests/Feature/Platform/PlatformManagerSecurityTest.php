<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Platform Manager Security Tests
 *
 * Verifies security controls for platform management endpoints:
 * - Only platform managers can access
 * - Non-PM users get 403 Forbidden
 * - PM flag cannot be mass-assigned
 * - Token revocation on PM revocation
 */
class PlatformManagerSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $platformManager;

    private User $regularOwner;

    private User $admin;

    private User $pbxUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);

        $this->regularOwner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        $this->pbxUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);
    }

    /**
     * @test
     * Platform managers can access dashboard
     */
    public function platform_manager_can_access_dashboard(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'organizations',
                    'users',
                    'extensions',
                    'dids',
                    'recent_organizations',
                    'recent_audit_logs',
                ],
            ]);
    }

    /**
     * @test
     * Non-platform manager owners cannot access platform endpoints
     */
    public function non_platform_manager_owner_gets_forbidden(): void
    {
        Sanctum::actingAs($this->regularOwner);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertForbidden()
            ->assertJson([
                'message' => 'Platform manager access required.',
            ]);
    }

    /**
     * @test
     * PBX admins cannot access platform endpoints
     */
    public function pbx_admin_gets_forbidden(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertForbidden();
    }

    /**
     * @test
     * PBX users cannot access platform endpoints
     */
    public function pbx_user_gets_forbidden(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertForbidden();
    }

    /**
     * @test
     * Unauthenticated users cannot access platform endpoints
     */
    public function unauthenticated_user_gets_unauthorized(): void
    {
        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertUnauthorized();
    }

    /**
     * @test
     * Platform manager can list all organizations
     */
    public function platform_manager_can_list_all_organizations(): void
    {
        // Create additional organizations
        Organization::factory()->count(3)->create();

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/organizations');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(4, $response->json('data'));
    }

    /**
     * @test
     * Platform manager can view organizations from other tenants
     */
    public function platform_manager_can_view_cross_tenant_organization(): void
    {
        $otherOrg = Organization::factory()->create([
            'name' => 'Other Tenant Org',
        ]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson("/api/v1/platform/organizations/{$otherOrg->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $otherOrg->id,
                    'name' => 'Other Tenant Org',
                ],
            ]);
    }

    /**
     * @test
     * Platform manager can update organization status
     */
    public function platform_manager_can_suspend_organization(): void
    {
        $targetOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->patchJson(
            "/api/v1/platform/organizations/{$targetOrg->id}/status",
            ['status' => 'suspended']
        );

        $response->assertOk();
        $this->assertDatabaseHas('organizations', [
            'id' => $targetOrg->id,
            'status' => OrganizationStatus::SUSPENDED->value,
        ]);
    }

    /**
     * @test
     * Suspending organization creates audit log entry
     */
    public function suspending_organization_creates_audit_log(): void
    {
        $targetOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        Sanctum::actingAs($this->platformManager);

        $this->patchJson(
            "/api/v1/platform/organizations/{$targetOrg->id}/status",
            ['status' => 'suspended', 'reason' => 'Test suspension']
        );

        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $targetOrg->id,
            'action' => 'organization.status.updated',
            'target_entity_type' => 'Organization',
        ]);
    }

    /**
     * @test
     * Non-platform manager cannot update organization status
     */
    public function non_pm_cannot_update_organization_status(): void
    {
        $targetOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        Sanctum::actingAs($this->regularOwner);

        $response = $this->patchJson(
            "/api/v1/platform/organizations/{$targetOrg->id}/status",
            ['status' => 'suspended']
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('organizations', [
            'id' => $targetOrg->id,
            'status' => OrganizationStatus::ACTIVE->value,
        ]);
    }

    /**
     * @test
     * Platform manager can grant platform manager status
     */
    public function platform_manager_can_grant_pm_status(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->patchJson(
            "/api/v1/platform/users/{$this->regularOwner->id}/platform-manager",
            ['is_platform_manager' => true]
        );

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $this->regularOwner->id,
            'is_platform_manager' => true,
        ]);
    }

    /**
     * @test
     * Granting PM status creates audit log
     */
    public function granting_pm_status_creates_audit_log(): void
    {
        Sanctum::actingAs($this->platformManager);

        $this->patchJson(
            "/api/v1/platform/users/{$this->regularOwner->id}/platform-manager",
            ['is_platform_manager' => true]
        );

        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $this->regularOwner->organization_id,
            'action' => 'user.platform_manager.granted',
            'target_entity_type' => 'User',
            'target_entity_id' => $this->regularOwner->id,
        ]);
    }

    /**
     * @test
     * Revoking PM status revokes all tokens
     */
    public function revoking_pm_status_revokes_tokens(): void
    {
        // Create a user with PM status and tokens
        $targetUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'is_platform_manager' => true,
        ]);

        // Create tokens for the user
        $token = $targetUser->createToken('test-token')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        Sanctum::actingAs($this->platformManager);

        $response = $this->patchJson(
            "/api/v1/platform/users/{$targetUser->id}/platform-manager",
            ['is_platform_manager' => false]
        );

        $response->assertOk();

        // All tokens should be revoked
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * @test
     * Cannot revoke last platform manager
     */
    public function cannot_revoke_last_platform_manager(): void
    {
        // Ensure only one PM exists
        User::where('is_platform_manager', true)
            ->where('id', '!=', $this->platformManager->id)
            ->update(['is_platform_manager' => false]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->patchJson(
            "/api/v1/platform/users/{$this->platformManager->id}/platform-manager",
            ['is_platform_manager' => false]
        );

        $response->assertUnprocessable()
            ->assertJson([
                'message' => 'Cannot revoke the last platform manager.',
            ]);
    }

    /**
     * @test
     * Non-PM cannot grant PM status
     */
    public function non_pm_cannot_grant_pm_status(): void
    {
        Sanctum::actingAs($this->regularOwner);

        $response = $this->patchJson(
            "/api/v1/platform/users/{$this->admin->id}/platform-manager",
            ['is_platform_manager' => true]
        );

        $response->assertForbidden();
        $this->assertDatabaseHas('users', [
            'id' => $this->admin->id,
            'is_platform_manager' => false,
        ]);
    }

    /**
     * @test
     * Platform manager can view audit logs
     */
    public function platform_manager_can_view_audit_logs(): void
    {
        // Create some audit logs
        PlatformAuditLog::factory()->count(5)->create([
            'platform_manager_user_id' => $this->platformManager->id,
        ]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/audit-logs');

        $response->assertOk()
            ->assertJsonCount(5, 'data');
    }

    /**
     * @test
     * Non-PM cannot view audit logs
     */
    public function non_pm_cannot_view_audit_logs(): void
    {
        Sanctum::actingAs($this->regularOwner);

        $response = $this->getJson('/api/v1/platform/audit-logs');

        $response->assertForbidden();
    }

    /**
     * @test
     * is_platform_manager is not mass assignable
     */
    public function is_platform_manager_not_mass_assignable(): void
    {
        Sanctum::actingAs($this->platformManager);

        // Try to create user with is_platform_manager set
        $response = $this->postJson(
            "/api/v1/platform/organizations/{$this->organization->id}/users",
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password123',
                'role' => 'pbx_user',
                'is_platform_manager' => true, // Attempt to set via mass assignment
            ]
        );

        $response->assertCreated();

        // Verify the flag was NOT set
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'is_platform_manager' => false,
        ]);
    }

    /**
     * @test
     * Platform endpoints require authentication
     */
    public function all_platform_endpoints_require_auth(): void
    {
        $endpoints = [
            ['GET', '/api/v1/platform/dashboard'],
            ['GET', '/api/v1/platform/organizations'],
            ['GET', '/api/v1/platform/users'],
            ['GET', '/api/v1/platform/audit-logs'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->json($method, $url);
            $response->assertUnauthorized();
        }
    }

    /**
     * @test
     * All platform mutations are audited
     */
    public function all_mutations_create_audit_logs(): void
    {
        Sanctum::actingAs($this->platformManager);

        // Create organization
        $org = Organization::factory()->create();

        // Update organization
        $this->putJson("/api/v1/platform/organizations/{$org->id}", [
            'name' => 'Updated Name',
        ]);

        // Verify audit logs exist
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'organization.settings.updated',
            'platform_manager_user_id' => $this->platformManager->id,
        ]);
    }
}
