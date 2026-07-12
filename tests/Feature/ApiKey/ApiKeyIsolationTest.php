<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\BusinessHoursSchedule;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Organization $org): User
    {
        return User::factory()->for($org)->create(['role' => UserRole::OWNER->value]);
    }

    /**
     * Data isolation: a key authenticated for org A must only see org A's data,
     * even when hitting a resource it is granted. OrganizationScope + the ApiKey
     * carrying organization_id enforce this.
     */
    public function test_key_only_sees_its_own_organization_data(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        BusinessHoursSchedule::factory()->for($orgB)->create();

        [, $tokenA] = app(ApiKeyService::class)->create(
            organizationId: $orgA->id,
            name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'read']],
            createdBy: null,
        );

        $response = $this->withToken($tokenA)->getJson('/api/v1/business-hours');
        $response->assertStatus(200);
        // orgA has no schedules; orgB's schedule must not leak.
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * Management isolation: ApiKey is NOT ScopedBy(OrganizationScope), so route-
     * model binding resolves any key by id. The ApiKeyPolicy org-match is the ONLY
     * cross-org barrier for show/update/destroy — assert it holds for all three.
     */
    public function test_owner_cannot_view_update_or_revoke_another_orgs_key(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $ownerA = $this->ownerOf($orgA);
        $keyB = ApiKey::factory()->for($orgB)->create(['name' => 'B key']);

        // view
        $this->actingAs($ownerA)->getJson("/api/v1/api-keys/{$keyB->id}")->assertStatus(403);

        // update
        $this->actingAs($ownerA)->putJson("/api/v1/api-keys/{$keyB->id}", [
            'name' => 'hijacked',
        ])->assertStatus(403);

        // revoke
        $this->actingAs($ownerA)->deleteJson("/api/v1/api-keys/{$keyB->id}")->assertStatus(403);

        // org B's key is untouched.
        $keyB->refresh();
        $this->assertSame('B key', $keyB->name);
        $this->assertNull($keyB->revoked_at);
    }

    /**
     * An owner's key list must never include another org's keys.
     */
    public function test_key_list_is_scoped_to_the_owners_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $ownerA = $this->ownerOf($orgA);
        ApiKey::factory()->for($orgA)->create();
        ApiKey::factory()->for($orgB)->create();

        $response = $this->actingAs($ownerA)->getJson('/api/v1/api-keys');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
