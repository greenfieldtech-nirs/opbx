<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnforceApiKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(array $permissions): string
    {
        $org = Organization::factory()->create();
        [, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: $permissions, createdBy: null,
        );

        return $plaintext;
    }

    public function test_read_grant_allows_get_but_blocks_write(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/business-hours')->assertStatus(200);
        $this->withToken($token)->postJson('/api/v1/business-hours', [])->assertStatus(403);
    }

    public function test_write_grant_allows_write_verbs(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'write']]);

        // 422 (validation) is acceptable here — it proves the request passed the
        // scope gate and reached the controller. A 403 would mean the gate blocked it.
        $status = $this->withToken($token)->postJson('/api/v1/business-hours', [])->status();
        $this->assertNotSame(403, $status);
    }

    /**
     * Write implies read: a write grant must permit read verbs (GET). Guards
     * against a regression where WRITE stops covering GET/HEAD.
     */
    public function test_write_grant_permits_read_verbs(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'write']]);

        $this->withToken($token)->getJson('/api/v1/business-hours')->assertStatus(200);
    }

    public function test_ungranted_resource_is_forbidden(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/recordings')->assertStatus(403);
    }

    /**
     * A read grant must block ALL write verbs, not just POST. Guards against a
     * regression that narrows ApiKeyPermissionLevel::permitsMethod to reject
     * only POST while letting PUT/PATCH/DELETE slip through.
     */
    public function test_read_grant_blocks_all_write_verbs(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'read']]);

        $this->withToken($token)->putJson('/api/v1/business-hours/1', [])->assertStatus(403);
        $this->withToken($token)->patchJson('/api/v1/business-hours/1', [])->assertStatus(403);
        $this->withToken($token)->deleteJson('/api/v1/business-hours/1')->assertStatus(403);
    }

    /**
     * Deny-by-default cornerstone: a route that is NOT a grantable resource
     * (fromRouteName returns null) must be forbidden for a key even with a read
     * verb. profile.show is a GET a User could call, but is not grantable.
     */
    public function test_non_grantable_route_is_forbidden_even_for_read_verb(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'write']]);

        $this->withToken($token)->getJson('/api/v1/profile')->assertStatus(403);
    }

    /**
     * A normal User is not subject to key scope enforcement: the same
     * non-grantable route a key is forbidden from must remain reachable for a
     * User, proving the middleware's instanceof-ApiKey guard passes Users through.
     */
    public function test_user_is_not_subject_to_scope_enforcement(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create();

        $this->actingAs($user)->getJson('/api/v1/profile')->assertStatus(200);
    }
}
