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

    public function test_application_config_derives_endpoints_from_request_and_config(): void
    {
        Config::set('services.mcp.port', 9090);

        // Full URL: the test client builds the request with that host/scheme.
        $response = $this->getJson('https://pbx.example.com/api/v1/config/application');

        $response->assertOk();
        $response->assertJsonStructure([
            'endpoints' => ['api_base_url', 'mcp_url', 'mcp_port'],
        ]);

        $endpoints = $response->json('endpoints');
        // Host comes from the request, port from services.mcp.port.
        $this->assertStringContainsString('pbx.example.com', $endpoints['api_base_url']);
        $this->assertStringEndsWith('/api/v1', $endpoints['api_base_url']);
        $this->assertSame(9090, $endpoints['mcp_port']);
        $this->assertStringContainsString(':9090/mcp', $endpoints['mcp_url']);
        // MCP URL uses the request host without the UI port.
        $this->assertStringContainsString('pbx.example.com', $endpoints['mcp_url']);
    }

    public function test_endpoints_prefer_forwarded_origin_from_proxy(): void
    {
        // Simulates browser -> dev/reverse proxy -> app (proxy rewrites Host to
        // an internal name): forwarded headers must win, ports must be handled.
        $response = $this->getJson(
            'http://nginx/api/v1/config/application',
            ['X-Forwarded-Host' => 'pbx.example.com:8443', 'X-Forwarded-Proto' => 'https'],
        );

        $response->assertOk();
        $endpoints = $response->json('endpoints');
        $this->assertSame('https://pbx.example.com:8443/api/v1', $endpoints['api_base_url']);
        // MCP URL: forwarded host WITHOUT the UI port, with the MCP port.
        $this->assertSame('https://pbx.example.com:8080/mcp', $endpoints['mcp_url']);
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
