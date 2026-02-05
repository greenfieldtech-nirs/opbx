<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AiAssistant;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiAssistantService $service;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AiAssistantService::class);

        // Create test organization and user
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_create_sip_ai_assistant()
    {
        $data = [
            'name' => 'Test SIP Assistant',
            'description' => 'Test description',
            'status' => 'active',
            'provider' => 'vapi',
            'configuration' => [
                'phone_number' => '+15551234567',
            ],
        ];

        $assistant = $this->service->create($data, $this->organization->id, $this->user->id);

        $this->assertInstanceOf(AiAssistant::class, $assistant);
        $this->assertEquals('Test SIP Assistant', $assistant->name);
        $this->assertEquals('vapi', $assistant->provider);
        $this->assertEquals('sip', $assistant->protocol);
        $this->assertEquals('+15551234567', $assistant->configuration['phone_number']);
        $this->assertEquals($this->user->id, $assistant->created_by);
    }

    public function test_create_websocket_ai_assistant()
    {
        $data = [
            'name' => 'Test WebSocket Assistant',
            'description' => 'Test description',
            'status' => 'active',
            'provider' => 'deepdub',
            'configuration' => [
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ];

        $assistant = $this->service->create($data, $this->organization->id, $this->user->id);

        $this->assertInstanceOf(AiAssistant::class, $assistant);
        $this->assertEquals('Test WebSocket Assistant', $assistant->name);
        $this->assertEquals('deepdub', $assistant->provider);
        $this->assertEquals('websocket', $assistant->protocol);
        $this->assertEquals('bot123', $assistant->configuration['bot_id']);
    }

    public function test_create_auto_detects_protocol_from_provider()
    {
        $data = [
            'name' => 'Auto Protocol Assistant',
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '+15551234567'],
        ];

        $assistant = $this->service->create($data, $this->organization->id);

        $this->assertEquals('sip', $assistant->protocol);
    }

    public function test_create_throws_exception_for_unknown_provider()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown provider: invalid_provider');

        $data = [
            'name' => 'Invalid Assistant',
            'provider' => 'invalid_provider',
            'configuration' => [],
        ];

        $this->service->create($data, $this->organization->id);
    }

    public function test_update_ai_assistant()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Original Name',
            'provider' => 'vapi',
            'protocol' => 'sip',
        ]);

        $data = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ];

        $updated = $this->service->update($assistant, $data, $this->user->id);

        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals('Updated description', $updated->description);
        $this->assertEquals($this->user->id, $updated->updated_by);
    }

    public function test_update_changes_protocol_when_provider_changes()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'vapi',
            'protocol' => 'sip',
        ]);

        $data = [
            'provider' => 'deepdub',
            'configuration' => [
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ];

        $updated = $this->service->update($assistant, $data, $this->user->id);

        $this->assertEquals('deepdub', $updated->provider);
        $this->assertEquals('websocket', $updated->protocol);
    }

    public function test_delete_ai_assistant_not_in_use()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $result = $this->service->delete($assistant);

        $this->assertTrue($result);
        $this->assertSoftDeleted('ai_assistants', ['id' => $assistant->id]);
    }

    public function test_delete_throws_exception_when_in_use()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create extension using this assistant
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'type' => 'ai_assistant',
            'configuration' => ['ai_assistant_id' => $assistant->id],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete AI Assistant that is in use');

        $this->service->delete($assistant);
    }

    public function test_get_usage_stats_returns_correct_count()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create 3 extensions using this assistant
        Extension::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'type' => 'ai_assistant',
            'configuration' => ['ai_assistant_id' => $assistant->id],
        ]);

        $stats = $this->service->getUsageStats($assistant);

        $this->assertEquals(3, $stats['usage_count']);
        $this->assertCount(3, $stats['extensions']);
    }

    public function test_validate_configuration_with_valid_data()
    {
        $result = $this->service->validateConfiguration('vapi', [
            'phone_number' => '+15551234567',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_configuration_with_missing_required_field()
    {
        $result = $this->service->validateConfiguration('vapi', []);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('phone_number', $result['errors']);
    }

    public function test_validate_configuration_with_invalid_phone_number()
    {
        $result = $this->service->validateConfiguration('vapi', [
            'phone_number' => 'invalid',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('phone_number', $result['errors']);
    }

    public function test_validate_configuration_with_unknown_provider()
    {
        $result = $this->service->validateConfiguration('unknown_provider', []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_model_is_websocket_method()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'protocol' => 'websocket',
        ]);

        $this->assertTrue($assistant->isWebSocket());
        $this->assertFalse($assistant->isSip());
    }

    public function test_model_is_sip_method()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'protocol' => 'sip',
        ]);

        $this->assertTrue($assistant->isSip());
        $this->assertFalse($assistant->isWebSocket());
    }

    public function test_model_get_provider_definition()
    {
        $assistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'vapi',
        ]);

        $definition = $assistant->getProviderDefinition();

        $this->assertNotNull($definition);
        $this->assertEquals('vapi', $definition->key);
        $this->assertEquals('sip', $definition->protocol);
    }

    public function test_model_scope_active()
    {
        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);
        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'inactive',
        ]);

        $activeCount = AiAssistant::active()->count();

        $this->assertEquals(1, $activeCount);
    }

    public function test_model_scope_by_protocol()
    {
        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'protocol' => 'sip',
        ]);
        AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'protocol' => 'websocket',
        ]);

        $sipCount = AiAssistant::byProtocol('sip')->count();
        $wsCount = AiAssistant::byProtocol('websocket')->count();

        $this->assertEquals(1, $sipCount);
        $this->assertEquals(1, $wsCount);
    }
}
