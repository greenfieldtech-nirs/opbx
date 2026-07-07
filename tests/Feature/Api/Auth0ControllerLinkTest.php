<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
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
}
