<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SocialIdentityProvider;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Auth0ControllerCallbackTest extends TestCase
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

    private function fakeAuth0Responses(string $email = 'user@example.com'): string
    {
        $redirect = $this->postJson('/api/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => $email,
                'email_verified' => true,
                'name' => 'Test User',
            ], 200),
        ]);

        return $state;
    }

    public function test_callback_logs_in_existing_identity(): void
    {
        $this->enableAuth0();
        $identity = UserSocialIdentity::factory()->create([
            'provider' => SocialIdentityProvider::GOOGLE,
            'provider_subject' => 'google-oauth2|123',
        ]);
        $state = $this->fakeAuth0Responses($identity->provider_email);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $response->assertJsonPath('user.id', $identity->user->id);
        $response->assertJsonPath('access_token', fn ($t) => ! empty($t));
    }

    public function test_callback_login_response_includes_platform_manager_flag(): void
    {
        $this->enableAuth0();

        // Existing platform owner links & signs in via Google.
        $identity = UserSocialIdentity::factory()->create([
            'provider' => SocialIdentityProvider::GOOGLE,
            'provider_subject' => 'google-oauth2|123',
        ]);
        $identity->user->update(['is_platform_manager' => true]);

        $state = $this->fakeAuth0Responses($identity->provider_email);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        // The Auth0 login payload must carry is_platform_manager so the frontend
        // does not overwrite the stored user and drop platform-owner status.
        $response->assertJsonPath('user.is_platform_manager', true);
        $response->assertJsonStructure([
            'user' => ['id', 'organization_id', 'name', 'email', 'role', 'status', 'is_platform_manager', 'social_identities'],
        ]);
    }

    public function test_callback_returns_registration_required_for_new_user(): void
    {
        $this->enableAuth0();
        $state = $this->fakeAuth0Responses('new@example.com');

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'AUTH0_REGISTRATION_REQUIRED');
    }

    public function test_callback_creates_organization_when_intent_is_register(): void
    {
        $this->enableAuth0();
        $redirect = $this->postJson('/api/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'register',
        ]);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'newowner@example.com',
                'email_verified' => true,
                'name' => 'New Owner',
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertOk();
        $response->assertJsonPath('user.role', 'owner');
        $this->assertDatabaseHas('organizations', ['name' => 'newowner@example.com Organization']);
    }

    public function test_callback_rejects_unverified_email(): void
    {
        $this->enableAuth0();
        $redirect = $this->postJson('/api/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);
        $state = $redirect->json('state');

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'unverified@example.com',
                'email_verified' => false,
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/auth/auth0/callback?code=valid&state={$state}");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'AUTH0_EMAIL_UNVERIFIED');
    }
}
