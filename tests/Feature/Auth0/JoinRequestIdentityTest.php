<?php

declare(strict_types=1);

namespace Tests\Feature\Auth0;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class JoinRequestIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function cacheProfile(string $id): void
    {
        Cache::put('auth0:onboarding:'.$id, [
            'provider' => 'google',
            'subject' => 'google-oauth2|real',
            'email' => 'real@example.com',
            'name' => 'Real Person',
        ], now()->addMinutes(15));
    }

    public function test_body_identity_is_ignored_and_cached_identity_is_used(): void
    {
        $org = Organization::factory()->create(['status' => 'active']);
        $this->cacheProfile('good');

        $this->postJson('/api/v1/organizations/join-requests', [
            'organization_slug' => $org->slug,
            'onboarding_id' => 'good',
            'provider_subject' => 'google-oauth2|victim',
            'email' => 'victim@example.com',
        ])->assertCreated();

        $this->assertDatabaseHas('organization_join_requests', [
            'organization_id' => $org->id,
            'email' => 'real@example.com',
            'provider_subject' => 'google-oauth2|real',
        ]);
        $this->assertDatabaseMissing('organization_join_requests', ['email' => 'victim@example.com']);
        $this->assertNull(Cache::get('auth0:onboarding:good'));
    }

    public function test_unknown_or_used_onboarding_id_is_422(): void
    {
        $org = Organization::factory()->create(['status' => 'active']);

        $this->postJson('/api/v1/organizations/join-requests', [
            'organization_slug' => $org->slug,
            'onboarding_id' => 'nope',
        ])->assertStatus(422);

        $this->assertDatabaseCount('organization_join_requests', 0);
    }

    public function test_expired_onboarding_id_is_422(): void
    {
        $org = Organization::factory()->create(['status' => 'active']);
        $this->cacheProfile('old');
        $this->travel(16)->minutes();

        $this->postJson('/api/v1/organizations/join-requests', [
            'organization_slug' => $org->slug,
            'onboarding_id' => 'old',
        ])->assertStatus(422);
    }
}
