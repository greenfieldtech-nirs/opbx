<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Platform Audit Log Smoke Test
 *
 * Verifies that audited platform management actions are recorded and returned
 * by the audit log endpoint in a paginated shape.
 */
class PlatformAuditLogSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $platformManager;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);
    }

    public function test_audit_log_records_organization_status_change(): void
    {
        Sanctum::actingAs($this->platformManager, ['*']);

        $targetOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);

        $this->patchJson("/api/v1/platform/organizations/{$targetOrg->id}/status", [
            'status' => OrganizationStatus::SUSPENDED->value,
        ])->assertOk();

        $response = $this->getJson('/api/v1/platform/audit-logs');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'organization.status.updated')
            ->assertJsonPath('data.0.target_entity_type', 'Organization')
            ->assertJsonPath('data.0.target_entity_id', $targetOrg->id);
    }

    public function test_audit_log_records_platform_manager_grant(): void
    {
        $targetUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        Sanctum::actingAs($this->platformManager, ['*']);

        $this->patchJson("/api/v1/platform/users/{$targetUser->id}/platform-manager", [
            'is_platform_manager' => true,
        ])->assertOk();

        $response = $this->getJson('/api/v1/platform/audit-logs');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.platform_manager.granted')
            ->assertJsonPath('data.0.target_entity_type', 'User')
            ->assertJsonPath('data.0.target_entity_id', $targetUser->id);
    }

    public function test_audit_log_filters_by_action(): void
    {
        Sanctum::actingAs($this->platformManager, ['*']);

        $targetOrg = Organization::factory()->create(['status' => OrganizationStatus::ACTIVE->value]);
        $targetUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        $this->patchJson("/api/v1/platform/organizations/{$targetOrg->id}/status", [
            'status' => OrganizationStatus::SUSPENDED->value,
        ]);

        $this->patchJson("/api/v1/platform/users/{$targetUser->id}/platform-manager", [
            'is_platform_manager' => true,
        ]);

        $response = $this->getJson('/api/v1/platform/audit-logs?action=organization.status.updated');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'organization.status.updated');
    }
}
