<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Platform Audit Service Unit Tests
 *
 * Tests the audit logging service in isolation.
 */
class PlatformAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformAuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditService = new PlatformAuditService;
    }

    /**
     * @test
     * Audit service creates log entry with all fields
     */
    public function creates_audit_log_with_all_fields(): void
    {
        $platformManager = User::factory()->create(['is_platform_manager' => true]);
        $organization = Organization::factory()->create();

        $request = new Request;
        $request->setUserResolver(fn () => $platformManager);

        $log = $this->auditService->log(
            request: $request,
            action: 'test.action',
            targetOrganizationId: $organization->id,
            targetEntityType: 'User',
            targetEntityId: 123,
            beforeState: ['name' => 'Old Name'],
            afterState: ['name' => 'New Name'],
            reason: 'Test reason'
        );

        $this->assertDatabaseHas('platform_audit_logs', [
            'id' => $log->id,
            'platform_manager_user_id' => $platformManager->id,
            'target_organization_id' => $organization->id,
            'action' => 'test.action',
            'target_entity_type' => 'User',
            'target_entity_id' => 123,
            'reason' => 'Test reason',
        ]);

        $this->assertEquals(['name' => 'Old Name'], $log->before_state);
        $this->assertEquals(['name' => 'New Name'], $log->after_state);
    }

    /**
     * @test
     * Audit service handles null request
     */
    public function handles_null_request(): void
    {
        $organization = Organization::factory()->create();

        $log = $this->auditService->log(
            request: null,
            action: 'system.action',
            targetOrganizationId: $organization->id,
            targetEntityType: 'Organization',
            targetEntityId: $organization->id,
            afterState: ['status' => 'active']
        );

        $this->assertDatabaseHas('platform_audit_logs', [
            'id' => $log->id,
            'platform_manager_user_id' => null,
            'action' => 'system.action',
        ]);
    }

    /**
     * @test
     * Audit service captures IP address from request
     */
    public function captures_ip_address_from_request(): void
    {
        $platformManager = User::factory()->create(['is_platform_manager' => true]);
        $organization = Organization::factory()->create();

        $request = Request::create('/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.100',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);
        $request->setUserResolver(fn () => $platformManager);

        $log = $this->auditService->log(
            request: $request,
            action: 'test.action',
            targetOrganizationId: $organization->id,
            targetEntityType: 'User',
            targetEntityId: 123
        );

        $this->assertEquals('192.168.1.100', $log->ip_address);
        $this->assertEquals('TestAgent/1.0', $log->user_agent);
    }

    /**
     * @test
     * Audit service handles missing states
     */
    public function handles_missing_states(): void
    {
        $platformManager = User::factory()->create(['is_platform_manager' => true]);
        $organization = Organization::factory()->create();

        $request = new Request;
        $request->setUserResolver(fn () => $platformManager);

        $log = $this->auditService->log(
            request: $request,
            action: 'test.action',
            targetOrganizationId: $organization->id
        );

        $this->assertNull($log->before_state);
        $this->assertNull($log->after_state);
    }

    /**
     * @test
     * Audit log can be retrieved with relationships
     */
    public function retrieves_with_relationships(): void
    {
        $platformManager = User::factory()->create([
            'name' => 'PM User',
            'is_platform_manager' => true,
        ]);
        $organization = Organization::factory()->create(['name' => 'Test Org']);

        $log = PlatformAuditLog::factory()
            ->byPlatformManager($platformManager)
            ->forOrganization($organization)
            ->create([
                'action' => 'test.action',
            ]);

        $retrieved = PlatformAuditLog::with([
            'platformManager:id,name,email',
            'targetOrganization:id,name,slug',
        ])->find($log->id);

        $this->assertEquals('PM User', $retrieved->platformManager->name);
        $this->assertEquals('Test Org', $retrieved->targetOrganization->name);
    }

    /**
     * @test
     * Audit log query can filter by action
     */
    public function query_filters_by_action(): void
    {
        $platformManager = User::factory()->create(['is_platform_manager' => true]);

        PlatformAuditLog::factory()->count(3)->create([
            'platform_manager_user_id' => $platformManager->id,
            'action' => 'user.created',
        ]);

        PlatformAuditLog::factory()->count(2)->create([
            'platform_manager_user_id' => $platformManager->id,
            'action' => 'organization.updated',
        ]);

        $userLogs = PlatformAuditLog::where('action', 'user.created')->count();
        $orgLogs = PlatformAuditLog::where('action', 'organization.updated')->count();

        $this->assertEquals(3, $userLogs);
        $this->assertEquals(2, $orgLogs);
    }

    /**
     * @test
     * Audit log query can filter by platform manager
     */
    public function query_filters_by_platform_manager(): void
    {
        $pm1 = User::factory()->create(['is_platform_manager' => true]);
        $pm2 = User::factory()->create(['is_platform_manager' => true]);

        PlatformAuditLog::factory()->count(5)->create([
            'platform_manager_user_id' => $pm1->id,
        ]);

        PlatformAuditLog::factory()->count(3)->create([
            'platform_manager_user_id' => $pm2->id,
        ]);

        $pm1Logs = PlatformAuditLog::where('platform_manager_user_id', $pm1->id)->count();
        $pm2Logs = PlatformAuditLog::where('platform_manager_user_id', $pm2->id)->count();

        $this->assertEquals(5, $pm1Logs);
        $this->assertEquals(3, $pm2Logs);
    }

    /**
     * @test
     * Audit logs are ordered by created_at desc by default
     */
    public function ordered_by_created_at_desc(): void
    {
        $platformManager = User::factory()->create(['is_platform_manager' => true]);

        $oldLog = PlatformAuditLog::factory()->create([
            'platform_manager_user_id' => $platformManager->id,
            'created_at' => now()->subDays(2),
        ]);

        $newLog = PlatformAuditLog::factory()->create([
            'platform_manager_user_id' => $platformManager->id,
            'created_at' => now(),
        ]);

        $logs = PlatformAuditLog::orderBy('created_at', 'desc')->get();

        $this->assertEquals($newLog->id, $logs->first()->id);
        $this->assertEquals($oldLog->id, $logs->last()->id);
    }
}
