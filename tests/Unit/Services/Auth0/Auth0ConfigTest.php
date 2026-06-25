<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Services\Auth0\Auth0Config;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class Auth0ConfigTest extends TestCase
{
    public function test_from_config_returns_disabled_when_feature_off(): void
    {
        Config::set('services.auth0.enabled', false);

        $config = Auth0Config::fromConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function test_from_config_parses_providers(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');
        Config::set('services.auth0.providers', ['google', 'github']);

        $config = Auth0Config::fromConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertSame('tenant.us.auth0.com', $config->domain);
        $this->assertSame(['google', 'github'], array_map(fn ($p) => $p->value, $config->providers));
        $this->assertSame('https://tenant.us.auth0.com/authorize', $config->getAuthorizeUrl());
    }

    public function test_throws_when_enabled_but_missing_config(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', '');

        $this->expectException(InvalidArgumentException::class);

        Auth0Config::fromConfig();
    }
}
