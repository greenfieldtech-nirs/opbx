<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the platform impersonation endpoints:
 *   POST /api/v1/platform/organizations/{organization}/impersonate
 *   POST /api/v1/platform/impersonation/stop
 */
class PlatformImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ownOrg;

    private Organization $targetOrg;

    private User $platformManager;

    private User $regularOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        $this->targetOrg = Organization::factory()->create([
            'status' => OrganizationStatus::ACTIVE->value,
        ]);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->ownOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => true,
        ]);

        $this->regularOwner = User::factory()->create([
            'organization_id' => $this->ownOrg->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => false,
        ]);
    }

    /**
     * @test
     */
    public function platform_manager_can_start_impersonation(): void
    {
        $token = $this->platformManager->createToken('platform')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate");

        $response->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'impersonating',
                'organization' => ['id', 'name', 'slug', 'status'],
            ])
            ->assertJson([
                'impersonating' => true,
                'organization' => ['id' => $this->targetOrg->id],
            ]);

        // The minted token is stamped with the target org.
        $accessToken = $response->json('access_token');
        [$id] = explode('|', $accessToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $id,
            'tokenable_id' => $this->platformManager->id,
            'impersonated_organization_id' => $this->targetOrg->id,
        ]);
    }

    /**
     * @test
     */
    public function starting_impersonation_is_audited(): void
    {
        $token = $this->platformManager->createToken('platform')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate")
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $this->targetOrg->id,
            'action' => 'organization.impersonation.started',
            'target_entity_type' => 'Organization',
            'target_entity_id' => $this->targetOrg->id,
        ]);
    }

    /**
     * @test
     */
    public function starting_impersonation_does_not_revoke_platform_token(): void
    {
        $platformToken = $this->platformManager->createToken('platform')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$platformToken}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate")
            ->assertOk();

        // Original platform token still works.
        $this->withHeader('Authorization', "Bearer {$platformToken}")
            ->getJson('/api/v1/platform/dashboard')
            ->assertOk();
    }

    /**
     * @test
     */
    public function non_platform_manager_cannot_start_impersonation(): void
    {
        $token = $this->regularOwner->createToken('normal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate")
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'impersonated_organization_id' => $this->targetOrg->id,
        ]);
    }

    /**
     * @test
     */
    public function cannot_impersonate_inactive_organization(): void
    {
        $this->targetOrg->update(['status' => OrganizationStatus::SUSPENDED->value]);

        $token = $this->platformManager->createToken('platform')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate")
            ->assertStatus(422)
            ->assertJson(['error' => 'ImpersonationUnavailable']);
    }

    /**
     * @test
     */
    public function can_stop_impersonation(): void
    {
        // Start impersonation to get a real impersonation token.
        $platformToken = $this->platformManager->createToken('platform')->plainTextToken;
        $start = $this->withHeader('Authorization', "Bearer {$platformToken}")
            ->postJson("/api/v1/platform/organizations/{$this->targetOrg->id}/impersonate")
            ->assertOk();

        $impersonationToken = $start->json('access_token');
        [$impId] = explode('|', $impersonationToken);

        $this->app['auth']->forgetGuards();

        // Stop using the impersonation token.
        $this->withHeader('Authorization', "Bearer {$impersonationToken}")
            ->postJson('/api/v1/platform/impersonation/stop')
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The impersonation token is revoked.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $impId,
        ]);

        // And the stop is audited.
        $this->assertDatabaseHas('platform_audit_logs', [
            'platform_manager_user_id' => $this->platformManager->id,
            'target_organization_id' => $this->targetOrg->id,
            'action' => 'organization.impersonation.ended',
        ]);
    }

    /**
     * @test
     */
    public function stop_rejects_non_impersonation_session(): void
    {
        $platformToken = $this->platformManager->createToken('platform')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$platformToken}")
            ->postJson('/api/v1/platform/impersonation/stop')
            ->assertStatus(422)
            ->assertJson(['error' => 'NotImpersonating']);
    }

    /**
     * @test
     */
    public function expired_impersonation_token_is_rejected(): void
    {
        // Mint an already-expired impersonation token directly.
        $newToken = $this->platformManager->createToken(
            'impersonation:test',
            ['*'],
            now()->subMinute(),
        );
        /** @var PersonalAccessToken $token */
        $token = $newToken->accessToken;
        $token->forceFill(['impersonated_organization_id' => $this->targetOrg->id])->save();

        $this->withHeader('Authorization', "Bearer {$newToken->plainTextToken}")
            ->getJson('/api/v1/extensions')
            ->assertUnauthorized();
    }
}
