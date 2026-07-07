<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class Auth0ControllerRedirectTest extends TestCase
{
    public function test_redirect_returns_auth0_url_when_enabled(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google']);

        $response = $this->postJson('/api/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $response->assertOk();
        $response->assertJsonPath('redirect_url', fn ($url) => str_contains($url, 'https://tenant.us.auth0.com/authorize'));
    }

    public function test_redirect_returns_503_when_disabled(): void
    {
        Config::set('services.auth0.enabled', false);

        $response = $this->postJson('/api/v1/auth/auth0/redirect', [
            'provider' => 'google',
            'intent' => 'login',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AUTH0_NOT_CONFIGURED');
    }
}
