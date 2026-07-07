<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConfigurationControllerTest extends TestCase
{
    public function test_application_config_is_publicly_accessible(): void
    {
        $response = $this->getJson('/api/v1/config/application');

        $response->assertOk();
        $response->assertJsonStructure([
            'mode',
            'is_production',
            'saas_enabled',
            'auth0' => [
                'enabled',
            ],
        ]);
    }

    public function test_application_config_exposes_auth0_when_enabled(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'client-id');
        Config::set('services.auth0.providers', ['google', 'github']);

        $response = $this->getJson('/api/v1/config/application');

        $response->assertOk();
        $response->assertJsonPath('saas_enabled', true);
        $response->assertJsonPath('auth0.enabled', true);
        $response->assertJsonPath('auth0.domain', 'tenant.us.auth0.com');
        $response->assertJsonPath('auth0.client_id', 'client-id');
        $response->assertJsonPath('auth0.providers', ['google', 'github']);
    }

    public function test_application_config_hides_auth0_when_disabled(): void
    {
        Config::set('services.auth0.enabled', false);

        $response = $this->getJson('/api/v1/config/application');

        $response->assertOk();
        $response->assertJsonPath('saas_enabled', false);
        $response->assertJsonPath('auth0.enabled', false);
        $response->assertJsonMissingPath('auth0.client_secret');
    }
}
