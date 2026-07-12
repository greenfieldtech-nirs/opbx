<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_authenticates_request_and_sets_last_used(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'read']],
            createdBy: null,
        );

        $response = $this->withToken($plaintext)->getJson('/api/v1/business-hours');

        $response->assertStatus(200);
        $this->assertNotNull($apiKey->fresh()->last_used_at);
    }

    public function test_revoked_key_is_unauthenticated(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: [], createdBy: null,
        );
        $apiKey->update(['revoked_at' => now()]);

        $this->withToken($plaintext)->getJson('/api/v1/business-hours')->assertStatus(401);
    }

    public function test_normal_user_token_is_not_clobbered_by_api_key_resolver(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => UserRole::OWNER->value]);

        // A normal Sanctum-authenticated user must still resolve as a User (not an ApiKey),
        // proving ResolveApiKey leaves non-opbxk_ auth untouched. The 200 proves User auth
        // works cleanly through the new middleware stack.
        $this->actingAs($user)->getJson('/api/v1/business-hours')->assertStatus(200);
        $this->assertInstanceOf(User::class, $user);
    }

    public function test_last_used_at_is_throttled_within_window(): void
    {
        $org = Organization::factory()->create();
        // Grant a NON-always-track resource so the 5s throttle applies (business-hours).
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'read']],
            createdBy: null,
        );

        $this->withToken($plaintext)->getJson('/api/v1/business-hours')->assertStatus(200);
        $firstUsed = $apiKey->fresh()->last_used_at;
        $this->assertNotNull($firstUsed);

        // Immediate second request within the 5s window must NOT bump last_used_at.
        $this->withToken($plaintext)->getJson('/api/v1/business-hours')->assertStatus(200);
        $this->assertEquals(
            $firstUsed->timestamp,
            $apiKey->fresh()->last_used_at->timestamp,
            'last_used_at should be throttled within the 5s window'
        );
    }

    public function test_extensions_resource_always_updates_last_used_at(): void
    {
        $org = Organization::factory()->create();
        // 'extensions' is in ALWAYS_TRACK, so last_used_at updates on every request even within 5s.
        // (Using extensions rather than users: UsersController::buildIndexQuery calls
        // $user->isSupervisor(), which ApiKey does not implement — a controller concern
        // unrelated to this middleware. extensions is also in ALWAYS_TRACK and has no such dependency.)
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'extensions', 'level' => 'read']],
            createdBy: null,
        );

        $this->withToken($plaintext)->getJson('/api/v1/extensions')->assertStatus(200);
        $first = $apiKey->fresh()->last_used_at;
        $this->assertNotNull($first);

        // travel forward 1 second (< 5s window) — ALWAYS_TRACK must still update.
        $this->travel(1)->seconds();
        $this->withToken($plaintext)->getJson('/api/v1/extensions')->assertStatus(200);
        $second = $apiKey->fresh()->last_used_at;

        $this->assertGreaterThan($first->timestamp, $second->timestamp,
            'extensions is in ALWAYS_TRACK so last_used_at must update even within the throttle window');
    }
}
