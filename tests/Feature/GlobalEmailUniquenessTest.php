<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A user email is globally unique: it can never belong to two organizations.
 * Every endpoint that sets a user email answers a duplicate with a 422.
 */
final class GlobalEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private const TAKEN = 'taken@example.com';

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // The email already belongs to a user in ANOTHER organization.
        User::factory()->create(['email' => self::TAKEN, 'organization_id' => Organization::factory()->create()->id]);

        $this->org = Organization::factory()->create(['status' => 'active']);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
    }

    public function test_org_user_create_rejects_email_used_in_another_organization(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/users', [
            'name' => 'Dup',
            'email' => self::TAKEN,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'pbx_user',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_org_user_update_rejects_email_used_in_another_organization(): void
    {
        Sanctum::actingAs($this->owner);
        $user = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);

        $this->putJson("/api/v1/users/{$user->id}", ['email' => self::TAKEN])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_invite_rejects_email_used_in_another_organization(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/users/invite', ['email' => self::TAKEN]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('already been taken', $response->json('errors.email.0'));
    }

    public function test_platform_org_user_create_rejects_duplicate_email(): void
    {
        $manager = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER, 'is_platform_manager' => true]);
        Sanctum::actingAs($manager, ['*']);

        $this->postJson("/api/v1/platform/organizations/{$this->org->id}/users", [
            'name' => 'Dup',
            'email' => self::TAKEN,
            'password' => 'Password123!',
            'role' => 'pbx_user',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_platform_user_update_rejects_duplicate_email_but_allows_own(): void
    {
        $manager = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER, 'is_platform_manager' => true]);
        Sanctum::actingAs($manager, ['*']);
        $user = User::factory()->create(['organization_id' => $this->org->id, 'email' => 'me@example.com']);

        $this->putJson("/api/v1/platform/users/{$user->id}", ['email' => self::TAKEN])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->putJson("/api/v1/platform/users/{$user->id}", ['email' => 'me@example.com'])
            ->assertOk();
    }

    public function test_join_request_rejects_onboarding_email_already_registered(): void
    {
        Cache::put('auth0:onboarding:dup', [
            'provider' => 'google',
            'subject' => 'google-oauth2|dup',
            'email' => self::TAKEN,
            'name' => 'Dup',
        ], now()->addMinutes(15));

        $this->postJson('/api/v1/organizations/join-requests', [
            'organization_slug' => $this->org->slug,
            'onboarding_id' => 'dup',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_join_request_approve_rejects_email_registered_meanwhile(): void
    {
        Sanctum::actingAs($this->owner);
        $joinRequest = OrganizationJoinRequest::factory()->create([
            'organization_id' => $this->org->id,
            'email' => self::TAKEN,
        ]);

        $this->postJson("/api/v1/organizations/join-requests/{$joinRequest->id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('organization_join_requests', ['id' => $joinRequest->id, 'status' => 'pending']);
    }
}
