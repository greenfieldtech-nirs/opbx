<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Auth0ControllerLinkTest extends TestCase
{
    use RefreshDatabase;

    private function enableAuth0(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);
    }

    public function test_authenticated_user_can_initiate_link(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/auth0/link', ['provider' => 'google']);

        $response->assertOk();
        $response->assertJsonPath('redirect_url', fn ($url) => str_contains($url, 'connection=google-oauth2'));
    }

    public function test_callback_links_identity_when_emails_match(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $redirect = $this->postJson('/api/v1/auth/auth0/link', ['provider' => 'google']);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => $user->email,
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|123',
        ]);
    }

    public function test_callback_links_identity_when_email_casing_differs(): void
    {
        $this->enableAuth0();
        // Stored email has mixed casing; Auth0 returns it lowercased.
        $user = User::factory()->create(['email' => 'NirS@Cloudonix.io']);
        Sanctum::actingAs($user);

        $redirect = $this->postJson('/api/v1/auth/auth0/link', ['provider' => 'google']);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|case',
                'email' => 'nirs@cloudonix.io',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|case',
        ]);
    }

    public function test_callback_links_identity_for_user_outside_current_org_scope(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();

        // Build valid link state for this user, then hit the PUBLIC callback with
        // NO authenticated user (as happens on the real Auth0 browser redirect).
        // The user must still be found despite the global OrganizationScope.
        Sanctum::actingAs($user);
        $redirect = $this->postJson('/api/v1/auth/auth0/link', ['provider' => 'google']);
        $state = $redirect->json('state');
        app('auth')->forgetGuards();

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|noscope',
                'email' => $user->email,
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|noscope',
        ]);
    }

    public function test_callback_returns_conflict_when_identity_linked_to_another_user(): void
    {
        $this->enableAuth0();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // The same Google account is already linked to another user.
        UserSocialIdentity::create([
            'user_id' => $otherUser->id,
            'provider' => 'google',
            'provider_subject' => 'google-oauth2|dup',
            'provider_email' => $otherUser->email,
            'provider_data' => [],
        ]);

        Sanctum::actingAs($user);
        $redirect = $this->postJson('/api/v1/auth/auth0/link', ['provider' => 'google']);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|dup',
                'email' => $user->email,
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'AUTH0_LINK_ALREADY_LINKED');
        $this->assertDatabaseMissing('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|dup',
        ]);
    }
}
