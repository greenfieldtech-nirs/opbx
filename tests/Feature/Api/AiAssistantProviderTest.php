<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantProviderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user for authentication
        $this->user = User::factory()->create();
    }

    public function test_can_get_all_providers(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'providers',
                'grouped' => [
                    'sip',
                    'websocket',
                    'dummy',
                ],
                'protocols',
            ],
        ]);

        $data = $response->json('data');

        // Should have providers
        $this->assertNotEmpty($data['providers']);

        // Should have SIP, WebSocket, and Dummy providers
        $this->assertNotEmpty($data['grouped']['sip']);
        $this->assertNotEmpty($data['grouped']['websocket']);
        $this->assertNotEmpty($data['grouped']['dummy']);

        // Should have protocol list
        $this->assertEquals(['sip', 'websocket', 'dummy'], $data['protocols']);
    }

    public function test_provider_has_required_fields(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers');

        $response->assertStatus(200);

        $providers = $response->json('data.providers');
        $firstProvider = $providers[0];

        // Check required provider fields
        $this->assertArrayHasKey('key', $firstProvider);
        $this->assertArrayHasKey('name', $firstProvider);
        $this->assertArrayHasKey('protocol', $firstProvider);
        $this->assertArrayHasKey('config_fields', $firstProvider);
        $this->assertIsArray($firstProvider['config_fields']);
    }

    public function test_can_get_specific_provider(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/vapi');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'key',
                'name',
                'protocol',
                'url_template',
                'config_fields',
                'description',
            ],
        ]);

        $provider = $response->json('data');
        $this->assertEquals('vapi', $provider['key']);
        $this->assertEquals('VAPI', $provider['name']);
        $this->assertEquals('sip', $provider['protocol']);
    }

    public function test_returns_404_for_unknown_provider(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/unknown');

        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'Provider not found',
        ]);
    }

    public function test_can_get_providers_by_protocol(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/sip');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'key',
                    'name',
                    'protocol',
                    'config_fields',
                ],
            ],
        ]);

        $providers = $response->json('data');

        // All providers should have SIP protocol
        foreach ($providers as $provider) {
            $this->assertEquals('sip', $provider['protocol']);
        }
    }

    public function test_can_get_websocket_providers(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/websocket');

        $response->assertStatus(200);

        $providers = $response->json('data');

        // All providers should have WebSocket protocol
        foreach ($providers as $provider) {
            $this->assertEquals('websocket', $provider['protocol']);
            $this->assertNotNull($provider['url_template']);
        }
    }

    public function test_returns_400_for_invalid_protocol(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/invalid');

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Invalid protocol. Must be "sip", "websocket", or "dummy".',
        ]);
    }

    public function test_can_get_dummy_providers(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/dummy');

        $response->assertStatus(200);

        $providers = $response->json('data');

        // All providers should have dummy protocol
        foreach ($providers as $provider) {
            $this->assertEquals('dummy', $provider['protocol']);
            $this->assertEmpty($provider['config_fields']);
        }
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/ai-assistant/providers');

        $response->assertStatus(401);
    }

    public function test_sip_providers_have_phone_number_field(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/sip');

        $providers = $response->json('data');

        foreach ($providers as $provider) {
            $fieldNames = array_column($provider['config_fields'], 'name');
            $this->assertContains('phone_number', $fieldNames);

            // Find phone number field
            $phoneField = collect($provider['config_fields'])->firstWhere('name', 'phone_number');
            $this->assertTrue($phoneField['required']);
            $this->assertEquals('tel', $phoneField['type']);
        }
    }

    public function test_websocket_providers_have_required_config_fields(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/protocol/websocket');

        $providers = $response->json('data');

        $this->assertNotEmpty($providers);

        foreach ($providers as $provider) {
            $this->assertNotEmpty($provider['config_fields']);

            foreach ($provider['config_fields'] as $field) {
                $this->assertArrayHasKey('name', $field);
                $this->assertArrayHasKey('label', $field);
                $this->assertArrayHasKey('type', $field);
                $this->assertArrayHasKey('required', $field);
                $this->assertArrayHasKey('validation_rules', $field);
            }
        }
    }

    public function test_deepdub_provider_configuration(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/deepdub');

        $response->assertStatus(200);

        $provider = $response->json('data');

        $this->assertEquals('deepdub', $provider['key']);
        $this->assertEquals('DeepDub', $provider['name']);
        $this->assertEquals('websocket', $provider['protocol']);
        $this->assertNotNull($provider['url_template']);
        $this->assertStringContainsString('wss://', $provider['url_template']);

        // Should have bot_id and auth_token fields
        $fieldNames = array_column($provider['config_fields'], 'name');
        $this->assertContains('bot_id', $fieldNames);
        $this->assertContains('auth_token', $fieldNames);
    }

    public function test_dograh_cloud_provider_configuration(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/dograh-cloud');

        $response->assertStatus(200);

        $provider = $response->json('data');

        $this->assertEquals('dograh-cloud', $provider['key']);
        $this->assertEquals('Dograh Cloud', $provider['name']);
        $this->assertEquals('websocket', $provider['protocol']);
        $this->assertEquals('{websocket_endpoint}/{agent_uuid}', $provider['url_template']);

        $fieldNames = array_column($provider['config_fields'], 'name');
        $this->assertContains('websocket_endpoint', $fieldNames);
        $this->assertContains('agent_uuid', $fieldNames);

        $endpointField = collect($provider['config_fields'])->firstWhere('name', 'websocket_endpoint');
        $this->assertTrue($endpointField['read_only']);
        $this->assertEquals('wss://api.dograh.com/api/v1/agent-stream/cloudonix', $endpointField['default_value']);
    }

    public function test_dograh_oss_provider_configuration(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/ai-assistant/providers/dograh-oss');

        $response->assertStatus(200);

        $provider = $response->json('data');

        $this->assertEquals('dograh-oss', $provider['key']);
        $this->assertEquals('Dograh OSS', $provider['name']);
        $this->assertEquals('websocket', $provider['protocol']);
        $this->assertEquals('{websocket_endpoint}/{agent_uuid}', $provider['url_template']);

        $fieldNames = array_column($provider['config_fields'], 'name');
        $this->assertContains('websocket_endpoint', $fieldNames);
        $this->assertContains('agent_uuid', $fieldNames);

        $endpointField = collect($provider['config_fields'])->firstWhere('name', 'websocket_endpoint');
        $this->assertTrue($endpointField['required']);
        $this->assertFalse($endpointField['read_only'] ?? false);
    }
}
