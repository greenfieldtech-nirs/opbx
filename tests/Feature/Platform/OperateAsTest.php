<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Operate As Organization (platform-owner impersonation) feature tests.
 *
 * Verifies the header-driven scope switch performed by
 * {@see \App\Http\Middleware\ApplyOperateAsOrganization} and the
 * start/stop audit endpoints on {@see \App\Http\Controllers\Platform\PlatformOperateAsController}.
 */
class OperateAsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'X-Operate-As-Organization';

    private Organization $platformOrg;

    private Organization $targetOrg;

    private User $platformManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        $this->targetOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->platformOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);
    }

    /**
     * Seed a couple of users and an extension into the target organization.
     *
     * @return array{0: User, 1: User}
     */
    private function seedTargetOrg(): array
    {
        return OrganizationScope::bypass(function (): array {
            $ownerA = User::factory()->create([
                'organization_id' => $this->targetOrg->id,
                'role' => UserRole::OWNER,
                'status' => UserStatus::ACTIVE,
                'is_platform_manager' => false,
            ]);

            $userB = User::factory()->create([
                'organization_id' => $this->targetOrg->id,
                'role' => UserRole::PBX_USER,
                'status' => UserStatus::ACTIVE,
                'is_platform_manager' => false,
            ]);

            Extension::factory()->create([
                'organization_id' => $this->targetOrg->id,
            ]);

            return [$ownerA, $userB];
        });
    }

    /**
     * @test
     */
    public function platform_manager_with_header_reads_target_org_data(): void
    {
        [$ownerA, $userB] = $this->seedTargetOrg();

        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->getJson('/api/v1/users');

        $response->assertOk();

        $returnedIds = collect($response->json('data'))->pluck('id')->all();

        // The target org's users are visible.
        $this->assertContains($ownerA->id, $returnedIds);
        $this->assertContains($userB->id, $returnedIds);

        // The platform manager's own org user is NOT visible.
        $this->assertNotContains($this->platformManager->id, $returnedIds);

        // Every returned record belongs to the target organization.
        foreach ($response->json('data') as $record) {
            $this->assertSame($this->targetOrg->id, $record['organization_id']);
        }
    }

    /**
     * Regression test for the guard-divergence bug: OrganizationScope reads
     * auth()->user() while controllers/EnsureTenantScope read request()->user().
     * With a REAL bearer token (not Sanctum::actingAs, which seeds all guards and
     * masks the bug), the two must resolve to the SAME effective org, otherwise
     * globally-scoped reads leak the platform owner's home-org data.
     *
     * @test
     */
    public function real_token_operate_as_scopes_globally_scoped_reads_to_target_org(): void
    {
        // A DID (globally scoped by OrganizationScope) in each org.
        $homeDid = OrganizationScope::bypass(fn () => \App\Models\DidNumber::factory()->create([
            'organization_id' => $this->platformOrg->id,
        ]));
        $targetDid = OrganizationScope::bypass(fn () => \App\Models\DidNumber::factory()->create([
            'organization_id' => $this->targetOrg->id,
        ]));

        // Use a REAL personal access token so the sanctum guard is exercised
        // exactly as in production (this is what surfaced the auth() vs
        // request()->user() divergence).
        $token = $this->platformManager->createToken('test-operate-as')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            self::HEADER => (string) $this->targetOrg->id,
        ])->getJson('/api/v1/phone-numbers');

        $response->assertOk();

        $returnedIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($targetDid->id, $returnedIds, 'Target org DID must be visible.');
        $this->assertNotContains($homeDid->id, $returnedIds, 'Home-org DID must NOT leak.');

        foreach ($response->json('data') as $record) {
            $this->assertSame($this->targetOrg->id, $record['organization_id']);
        }
    }

    /**
     * @test
     */
    public function non_platform_manager_sending_header_is_forbidden(): void
    {
        $regularOwner = User::factory()->create([
            'organization_id' => $this->platformOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);

        Sanctum::actingAs($regularOwner);

        $response = $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->getJson('/api/v1/users');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'OPERATE_AS_FORBIDDEN');
    }

    /**
     * @test
     */
    public function suspended_target_org_is_forbidden(): void
    {
        $suspended = Organization::factory()->suspended()->create();

        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $suspended->id,
        ])->getJson('/api/v1/users');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'OPERATE_AS_ORG_INACTIVE');
    }

    /**
     * @test
     */
    public function deleted_target_org_is_forbidden(): void
    {
        $deleted = Organization::factory()->deleted()->create();

        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $deleted->id,
        ])->getJson('/api/v1/users');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'OPERATE_AS_ORG_INACTIVE');
    }

    /**
     * @test
     */
    public function nonexistent_target_org_returns_not_found(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => '999999',
        ])->getJson('/api/v1/users');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'OPERATE_AS_ORG_NOT_FOUND');
    }

    /**
     * @test
     */
    public function start_endpoint_writes_operate_as_started_audit_log(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->postJson(
            "/api/v1/platform/operate-as/{$this->targetOrg->id}",
            ['reason' => 'Investigating a support ticket']
        );

        $response->assertOk();
        $response->assertJsonPath('data.organization.id', $this->targetOrg->id);
        $response->assertJsonPath('data.organization.name', $this->targetOrg->name);

        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $this->targetOrg->id,
            'action' => 'operate_as.started',
            'reason' => 'Investigating a support ticket',
        ]);
    }

    /**
     * @test
     */
    public function start_endpoint_rejects_inactive_org(): void
    {
        $suspended = Organization::factory()->suspended()->create();

        Sanctum::actingAs($this->platformManager);

        $response = $this->postJson("/api/v1/platform/operate-as/{$suspended->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'OPERATE_AS_ORG_INACTIVE');
    }

    /**
     * @test
     */
    public function stop_endpoint_writes_operate_as_stopped_audit_log(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->deleteJson(
            '/api/v1/platform/operate-as',
            ['organization_id' => $this->targetOrg->id]
        );

        $response->assertNoContent();

        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $this->targetOrg->id,
            'action' => 'operate_as.stopped',
        ]);
    }

    /**
     * @test
     */
    public function me_reflects_operate_as_context(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->getJson('/api/v1/auth/me');

        $response->assertOk();

        $response->assertJsonPath('user.organization_id', $this->targetOrg->id);
        $response->assertJsonPath('user.role', UserRole::OWNER->value);
        $response->assertJsonPath('user.is_platform_manager', false);
        $response->assertJsonPath('user.operate_as.active', true);
        $response->assertJsonPath('user.operate_as.organization.id', $this->targetOrg->id);
        $response->assertJsonPath('user.operate_as.real_user_id', $this->platformManager->id);
    }

    /**
     * @test
     */
    public function me_has_no_operate_as_block_when_not_operating_as(): void
    {
        Sanctum::actingAs($this->platformManager);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonMissingPath('user.operate_as');
        $response->assertJsonPath('user.is_platform_manager', true);
    }

    /**
     * @test
     */
    public function effective_user_changes_are_not_persisted(): void
    {
        $this->seedTargetOrg();

        Sanctum::actingAs($this->platformManager);

        $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->getJson('/api/v1/users')->assertOk();

        // The real platform manager's DB row must be untouched.
        $fresh = OrganizationScope::bypass(
            fn (): User => User::withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($this->platformManager->id)
        );

        $this->assertSame($this->platformOrg->id, $fresh->organization_id);
        $this->assertSame(UserRole::OWNER, $fresh->role);
        $this->assertTrue($fresh->is_platform_manager);
    }

    /**
     * @test
     */
    public function self_mutating_profile_write_is_blocked_while_operating_as(): void
    {
        $this->seedTargetOrg();

        $originalName = $this->platformManager->name;

        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->putJson('/api/v1/profile', [
            'name' => 'Hijacked Name',
        ]);

        // The save() guard on the effective user must translate to a 403.
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'OPERATE_AS_SELF_MUTATION_FORBIDDEN');

        // The real platform manager's row is completely untouched.
        $fresh = OrganizationScope::bypass(
            fn (): User => User::withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($this->platformManager->id)
        );

        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($this->platformOrg->id, $fresh->organization_id);
        $this->assertSame(UserRole::OWNER, $fresh->role);
        $this->assertTrue($fresh->is_platform_manager);
    }

    /**
     * @test
     */
    public function password_change_is_blocked_while_operating_as(): void
    {
        $this->seedTargetOrg();

        $originalHash = $this->platformManager->password;

        Sanctum::actingAs($this->platformManager);

        $response = $this->withHeaders([
            self::HEADER => (string) $this->targetOrg->id,
        ])->putJson('/api/v1/profile/password', [
            'current_password' => 'password',
            'new_password' => 'NewSecret!2345',
            'new_password_confirmation' => 'NewSecret!2345',
        ]);

        // Must not succeed (guard blocks the save with a 403).
        $this->assertNotSame(200, $response->getStatusCode());

        $fresh = OrganizationScope::bypass(
            fn (): User => User::withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($this->platformManager->id)
        );

        // The real platform manager's password hash must be unchanged.
        $this->assertSame($originalHash, $fresh->password);
    }
}
