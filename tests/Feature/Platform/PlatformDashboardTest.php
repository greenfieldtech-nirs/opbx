<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Platform Dashboard Integration Tests
 *
 * Tests the dashboard endpoint with real data scenarios.
 */
class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $platformManager;

    private Organization $organization;

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
    }

    /**
     * @test
     * Dashboard returns correct organization counts
     */
    public function dashboard_returns_correct_organization_counts(): void
    {
        // Create organizations with different statuses
        Organization::factory()->count(3)->create(['status' => OrganizationStatus::ACTIVE->value]);
        Organization::factory()->count(2)->create(['status' => OrganizationStatus::SUSPENDED->value]);
        Organization::factory()->count(1)->create(['status' => OrganizationStatus::DELETED->value]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.organizations.total', 7) // 1 + 3 + 2 + 1
            ->assertJsonPath('data.organizations.active', 4) // 1 + 3
            ->assertJsonPath('data.organizations.suspended', 2)
            ->assertJsonPath('data.organizations.deleted', 1);
    }

    /**
     * @test
     * Dashboard returns correct user counts
     */
    public function dashboard_returns_correct_user_counts(): void
    {
        // Create users across different organizations
        $org2 = Organization::factory()->create();

        User::factory()->count(5)->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        User::factory()->count(3)->create([
            'organization_id' => $org2->id,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        // Create 2 platform managers
        User::factory()->count(2)->create([
            'organization_id' => $org2->id,
            'is_platform_manager' => true,
        ]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.users.total', 11) // 5 + 3 + 2 + 1 (PM)
            ->assertJsonPath('data.users.platform_managers', 3); // 2 + 1
    }

    /**
     * @test
     * Dashboard returns correct extension counts
     */
    public function dashboard_returns_correct_extension_counts(): void
    {
        $org2 = Organization::factory()->create();
        $org3 = Organization::factory()->create();

        // Create extensions in different organizations
        Extension::factory()->count(5)->create(['organization_id' => $this->organization->id]);
        Extension::factory()->count(3)->create(['organization_id' => $org2->id]);
        Extension::factory()->count(2)->create(['organization_id' => $org3->id]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.extensions.total', 10);
    }

    /**
     * @test
     * Dashboard returns recent organizations ordered by created_at
     */
    public function dashboard_returns_recent_organizations_ordered(): void
    {
        // Create organizations with specific dates
        $oldOrg = Organization::factory()->create([
            'created_at' => now()->subDays(5),
        ]);

        $newOrg = Organization::factory()->create([
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk();

        $recentOrgs = $response->json('data.recent_organizations');
        $this->assertGreaterThan(0, count($recentOrgs));

        // Most recent should be first
        if (count($recentOrgs) >= 2) {
            $firstCreatedAt = $recentOrgs[0]['created_at'];
            $lastCreatedAt = $recentOrgs[count($recentOrgs) - 1]['created_at'];
            $this->assertGreaterThanOrEqual($lastCreatedAt, $firstCreatedAt);
        }
    }

    /**
     * @test
     * Dashboard includes organization counts in recent organizations
     */
    public function dashboard_includes_organization_counts(): void
    {
        $org = Organization::factory()->create();

        User::factory()->count(3)->create(['organization_id' => $org->id]);
        Extension::factory()->count(5)->create(['organization_id' => $org->id]);

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk();

        $recentOrgs = $response->json('data.recent_organizations');
        $targetOrg = collect($recentOrgs)->first(fn ($o) => $o['id'] === $org->id);

        $this->assertNotNull($targetOrg);
        $this->assertEquals(3, $targetOrg['users_count']);
        $this->assertEquals(5, $targetOrg['extensions_count']);
    }

    /**
     * @test
     * Dashboard returns recent audit logs
     */
    public function dashboard_returns_recent_audit_logs(): void
    {
        Sanctum::actingAs($this->platformManager);

        // Perform some actions that create audit logs
        $targetOrg = Organization::factory()->create();

        $this->patchJson("/api/v1/platform/organizations/{$targetOrg->id}/status", [
            'status' => 'suspended',
        ]);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk();

        $auditLogs = $response->json('data.recent_audit_logs');
        $this->assertGreaterThan(0, count($auditLogs));

        // Check audit log structure
        $firstLog = $auditLogs[0];
        $this->assertArrayHasKey('id', $firstLog);
        $this->assertArrayHasKey('action', $firstLog);
        $this->assertArrayHasKey('platform_manager', $firstLog);
        $this->assertArrayHasKey('target_organization', $firstLog);
    }

    /**
     * @test
     * Dashboard limits recent organizations to 10
     */
    public function dashboard_limits_recent_organizations_to_10(): void
    {
        // Create 15 organizations
        Organization::factory()->count(15)->create();

        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk();

        $recentOrgs = $response->json('data.recent_organizations');
        $this->assertLessThanOrEqual(10, count($recentOrgs));
    }

    /**
     * @test
     * Dashboard limits recent audit logs to 10
     */
    public function dashboard_limits_recent_audit_logs_to_10(): void
    {
        Sanctum::actingAs($this->platformManager);

        // Perform multiple actions
        for ($i = 0; $i < 15; $i++) {
            $org = Organization::factory()->create();
            $this->patchJson("/api/v1/platform/organizations/{$org->id}/status", [
                'status' => 'suspended',
            ]);
        }

        $response = $this->getJson('/api/v1/platform/dashboard');

        $response->assertOk();

        $auditLogs = $response->json('data.recent_audit_logs');
        $this->assertLessThanOrEqual(10, count($auditLogs));
    }
}
