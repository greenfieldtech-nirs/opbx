<?php

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\Security\RoutingSentryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VoiceRoutingRefactorTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $organization;
    protected DidNumber $did;
    protected User $user;
    protected Extension $extension;
    protected CloudonixSettings $settings;
    protected string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        // Create Organization
        $this->organization = Organization::factory()->create(['status' => 'active']);

        // Create User
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        // Create Extension
        $this->extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        // Create a second extension for internal call testing
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1002',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        // Create DID linked to Extension
        $this->did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1234567890',
            'status' => 'active',
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->extension->id],
        ]);

        // Create Cloudonix Settings for Auth
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_requests_api_key' => $this->apiKey,
        ]);
    }

    private function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/xml',
        ];
    }

    /** @test */
    public function it_routes_inbound_call_to_user_extension()
    {
        // Mock Sentry to allow
        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1987654321',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }

    /** @test */
    public function it_blocks_call_if_sentry_denies()
    {
        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn([
                'allowed' => false,
                'reason' => 'Blacklisted',
                'action' => 'reject'
            ]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1666666666',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Hangup', $content);
    }

    /** @test */
    public function it_routes_to_ring_group()
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Sales',
            'status' => 'active',
            'strategy' => 'simultaneous',
            'timeout' => 20,
        ]);

        $ringGroup->members()->create([
            'extension_id' => $this->extension->id,
            'priority' => 1,
        ]);

        $this->did->update([
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $ringGroup->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial timeout="20">', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }

    /** @test */
    public function it_routes_internal_extension_call()
    {
        $this->mock(RoutingSentryService::class, function ($mock) {
            // Sentry check is skipped for internal calls in current Manager implementation
            $mock->shouldReceive('checkInbound')->never();
        });

        $response = $this->postJson(route('voice.route'), [
            'To' => $this->extension->extension_number,
            'From' => '1002',
            'Direction' => 'internal',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }

    /** @test */
    public function it_routes_to_ai_assistant_extension()
    {
        $aiExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2030',
            'type' => ExtensionType::AI_ASSISTANT,
            'status' => 'active',
            'service_url' => 'https://ai-service.example.com/webhook',
            'service_token' => 'secret-token-123',
            'service_params' => ['model' => 'gpt-4', 'language' => 'en'],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $aiExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial>', $content);
        $this->assertStringContainsString('<Service', $content);
        $this->assertStringContainsString('https://ai-service.example.com/webhook</Service>', $content);
        $this->assertStringContainsString('token="secret-token-123"', $content);
        $this->assertStringContainsString('model="gpt-4"', $content);
        $this->assertStringContainsString('language="en"', $content);
    }

    /** @test */
    public function it_returns_unavailable_for_ai_assistant_without_service_url()
    {
        $aiExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2031',
            'type' => ExtensionType::AI_ASSISTANT,
            'status' => 'active',
            'service_url' => null,
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $aiExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('AI Agent provider or phone number not configured', $content);
    }

    /** @test */
    public function ai_assistant_extension_has_service_configuration()
    {
        $aiExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2032',
            'type' => ExtensionType::AI_ASSISTANT,
            'status' => 'active',
            'service_url' => 'https://ai-service.example.com/webhook',
            'service_token' => 'secret-token-123',
            'service_params' => ['model' => 'gpt-4', 'language' => 'en'],
        ]);

        $this->assertEquals('https://ai-service.example.com/webhook', $aiExtension->service_url);
        $this->assertEquals('secret-token-123', $aiExtension->service_token);
        $this->assertEquals(['model' => 'gpt-4', 'language' => 'en'], $aiExtension->service_params);
    }

    /** @test */
    public function ai_agent_routing_strategy_generates_correct_cxml()
    {
        $aiExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2033',
            'type' => ExtensionType::AI_ASSISTANT,
            'status' => 'active',
            'service_url' => 'https://ai-service.example.com/webhook',
            'service_token' => 'secret-token-123',
            'service_params' => ['model' => 'gpt-4', 'language' => 'en'],
        ]);

        $strategy = new \App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy();
        $request = new \Illuminate\Http\Request();
        $did = new \App\Models\DidNumber();
        $destination = ['extension' => $aiExtension];

        $response = $strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->status());
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial>', $content);
        $this->assertStringContainsString('<Service', $content);
        $this->assertStringContainsString('https://ai-service.example.com/webhook</Service>', $content);
        $this->assertStringContainsString('token="secret-token-123"', $content);
        $this->assertStringContainsString('model="gpt-4"', $content);
        $this->assertStringContainsString('language="en"', $content);
    }

    /** @test */
    public function ai_agent_routing_strategy_handles_legacy_configuration()
    {
        $aiExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2034',
            'type' => ExtensionType::AI_ASSISTANT,
            'status' => 'active',
            'service_url' => null,
            'service_token' => null,
            'service_params' => null,
            'configuration' => [
                'provider' => 'retell',
                'phone_number' => '+12127773456',
            ],
        ]);

        $strategy = new \App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy();
        $request = new \Illuminate\Http\Request();
        $did = new \App\Models\DidNumber();
        $destination = ['extension' => $aiExtension];

        $response = $strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->status());
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial>', $content);
        $this->assertStringContainsString('<Service', $content);
        $this->assertStringContainsString('provider="retell"', $content);
        $this->assertStringContainsString('+12127773456</Service>', $content);
    }

    /** @test */
    public function it_routes_to_forward_extension()
    {
        $forwardExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2035',
            'type' => ExtensionType::FORWARD,
            'status' => 'active',
            'configuration' => [
                'forward_to' => '1001',
            ],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $forwardExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('<Number>1001</Number>', $content);
    }

    /** @test */
    public function forward_extension_returns_unavailable_when_target_not_found()
    {
        $forwardExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2036',
            'type' => ExtensionType::FORWARD,
            'status' => 'active',
            'configuration' => [
                'forward_to' => '9999',
            ],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $forwardExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Target extension 9999 not found', $content);
    }

    /** @test */
    public function forward_extension_returns_unavailable_when_target_inactive()
    {
        $inactiveExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1005',
            'type' => ExtensionType::USER,
            'status' => 'inactive',
        ]);

        $forwardExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2037',
            'type' => ExtensionType::FORWARD,
            'status' => 'active',
            'configuration' => [
                'forward_to' => '1005',
            ],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $forwardExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Target extension 1005 is inactive', $content);
    }

    /** @test */
    public function it_routes_forward_to_external_phone_number()
    {
        $forwardExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2038',
            'type' => ExtensionType::FORWARD,
            'status' => 'active',
            'configuration' => [
                'forward_to' => '+12127773456',
            ],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $forwardExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('<Number>+12127773456</Number>', $content);
    }

    /** @test */
    public function it_routes_forward_to_sip_uri()
    {
        $forwardExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2039',
            'type' => ExtensionType::FORWARD,
            'status' => 'active',
            'configuration' => [
                'forward_to' => 'sip:+12127773456@sip.gateway.com:5060',
            ],
        ]);

        $this->did->update([
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $forwardExtension->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('<Sip>sip:+12127773456@sip.gateway.com:5060</Sip>', $content);
    }
}
