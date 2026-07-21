<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that a real impersonation token (a Sanctum token stamped with
 * impersonated_organization_id) scopes normal API requests to the target
 * organization via the SetImpersonationContext middleware + OrganizationScope.
 *
 * Uses real bearer tokens (not Sanctum::actingAs) because the middleware relies
 * on $user->currentAccessToken() returning a persisted PersonalAccessToken.
 */
class ImpersonationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ownOrg;

    private Organization $targetOrg;

    private User $platformManager;

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
    }

    /**
     * Mint a real impersonation token for the platform manager targeting an org.
     */
    private function impersonationToken(int $organizationId): string
    {
        $newToken = $this->platformManager->createToken('impersonation:test');
        /** @var PersonalAccessToken $token */
        $token = $newToken->accessToken;
        $token->forceFill(['impersonated_organization_id' => $organizationId])->save();

        return $newToken->plainTextToken;
    }

    /**
     * @test
     * A normal (non-impersonation) token sees only the user's own org data.
     */
    public function normal_token_sees_only_own_org(): void
    {
        $ownExt = Extension::factory()->create(['organization_id' => $this->ownOrg->id]);
        $targetExt = Extension::factory()->create(['organization_id' => $this->targetOrg->id]);

        $token = $this->platformManager->createToken('normal')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/extensions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownExt->id, $ids);
        $this->assertNotContains($targetExt->id, $ids);
    }

    /**
     * @test
     * An impersonation token scopes reads to the TARGET organization.
     */
    public function impersonation_token_reads_target_org(): void
    {
        $ownExt = Extension::factory()->create(['organization_id' => $this->ownOrg->id]);
        $targetExt = Extension::factory()->create(['organization_id' => $this->targetOrg->id]);

        $token = $this->impersonationToken($this->targetOrg->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/extensions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($targetExt->id, $ids);
        $this->assertNotContains($ownExt->id, $ids);
    }

    /**
     * @test
     * Writes made with an impersonation token belong to the target organization.
     */
    public function impersonation_token_writes_to_target_org(): void
    {
        $token = $this->impersonationToken($this->targetOrg->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/extensions', [
                'extension_number' => '4321',
                'type' => 'user',
                'status' => 'active',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('extensions', [
            'extension_number' => '4321',
            'organization_id' => $this->targetOrg->id,
        ]);

        // And definitely NOT in the platform manager's own org.
        $this->assertDatabaseMissing('extensions', [
            'extension_number' => '4321',
            'organization_id' => $this->ownOrg->id,
        ]);
    }

    /**
     * @test
     * Impersonation context does not leak: a subsequent request with a normal
     * token is scoped to the user's own org again.
     */
    public function context_does_not_leak_between_requests(): void
    {
        $ownExt = Extension::factory()->create(['organization_id' => $this->ownOrg->id]);
        $targetExt = Extension::factory()->create(['organization_id' => $this->targetOrg->id]);

        $impersonationToken = $this->impersonationToken($this->targetOrg->id);
        $normalToken = $this->platformManager->createToken('normal')->plainTextToken;

        // Impersonated request first.
        $this->withHeader('Authorization', "Bearer {$impersonationToken}")
            ->getJson('/api/v1/extensions')
            ->assertOk();

        // Simulate a fresh request lifecycle: in production every request is a
        // new process, so the auth guard re-resolves the user + token. The test
        // process reuses the application instance, so we forget resolved guards
        // to avoid Sanctum returning the previously-cached user/token.
        $this->app['auth']->forgetGuards();

        // Then a normal request must see only own org.
        $response = $this->withHeader('Authorization', "Bearer {$normalToken}")
            ->getJson('/api/v1/extensions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownExt->id, $ids);
        $this->assertNotContains($targetExt->id, $ids);
    }

    /**
     * @test
     * An impersonation token targeting a suspended org is rejected.
     */
    public function impersonation_token_rejected_for_inactive_org(): void
    {
        $this->targetOrg->update(['status' => OrganizationStatus::SUSPENDED->value]);

        $token = $this->impersonationToken($this->targetOrg->id);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/extensions');

        $response->assertForbidden()
            ->assertJson(['error' => 'ImpersonationUnavailable']);
    }
}
