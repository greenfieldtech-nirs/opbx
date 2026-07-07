<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Services\Auth0\Auth0Config;
use App\Services\Auth0\Auth0ProfileNormalizer;
use App\Services\Auth0\Auth0Service;
use App\Services\Auth0\Auth0StateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class Auth0ServiceTest extends TestCase
{
    private Auth0Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth0.enabled' => true,
            'services.auth0.domain' => 'tenant.us.auth0.com',
            'services.auth0.client_id' => 'client-id',
            'services.auth0.client_secret' => 'client-secret',
            'services.auth0.redirect_uri' => 'https://app.opbx.com/ui/auth/callback',
            'services.auth0.providers' => ['google'],
        ]);

        $this->service = new Auth0Service(
            Auth0Config::fromConfig(),
            new Auth0StateStore,
            new Auth0ProfileNormalizer
        );
    }

    public function test_build_authorize_url_returns_valid_url(): void
    {
        $result = $this->service->buildAuthorizeUrl('google', 'login');

        $this->assertStringContainsString('https://tenant.us.auth0.com/authorize', $result['url']);
        $this->assertStringContainsString('connection=google-oauth2', $result['url']);
        $this->assertStringContainsString('state='.$result['state'], $result['url']);
        $this->assertStringContainsString('code_challenge=', $result['url']);
        $this->assertTrue(Cache::has('auth0:state:'.$result['state']));
    }

    public function test_build_authorize_url_includes_login_hint_when_email_provided(): void
    {
        $result = $this->service->buildAuthorizeUrl('google', 'login', null, 'user@example.com');

        $this->assertStringContainsString('login_hint=', $result['url']);
        $this->assertStringContainsString('user%40example.com', $result['url']);
    }

    public function test_build_authorize_url_rejects_disabled_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->buildAuthorizeUrl('github', 'login');
    }

    public function test_handle_callback_exchanges_code_and_fetches_profile(): void
    {
        $result = $this->service->buildAuthorizeUrl('google', 'login');
        $state = $result['state'];

        Http::fake([
            'tenant.us.auth0.com/oauth/token' => Http::response(['access_token' => 'token'], 200),
            'tenant.us.auth0.com/userinfo' => Http::response([
                'sub' => 'google-oauth2|123',
                'email' => 'user@example.com',
                'email_verified' => true,
                'name' => 'User',
            ], 200),
        ]);

        $profile = $this->service->handleCallback('auth-code', $state);

        $this->assertSame('google-oauth2|123', $profile['subject']);
        $this->assertSame('user@example.com', $profile['email']);
        $this->assertTrue($profile['email_verified']);
        $this->assertSame('login', $profile['intent']);
    }
}
