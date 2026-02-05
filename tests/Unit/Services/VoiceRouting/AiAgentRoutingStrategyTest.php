<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy;
use Illuminate\Http\Request;
use Tests\TestCase;

class AiAgentRoutingStrategyTest extends TestCase
{
    private AiAgentRoutingStrategy $strategy;

    private ProviderRegistry $providerRegistry;

    private WebSocketUrlBuilder $urlBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerRegistry = new ProviderRegistry;
        $this->urlBuilder = new WebSocketUrlBuilder;
        $this->strategy = new AiAgentRoutingStrategy($this->providerRegistry, $this->urlBuilder);
    }

    public function test_can_handle_ai_assistant_type(): void
    {
        $this->assertTrue($this->strategy->canHandle(ExtensionType::AI_ASSISTANT));
        $this->assertFalse($this->strategy->canHandle(ExtensionType::USER));
        $this->assertFalse($this->strategy->canHandle(ExtensionType::CONFERENCE));
    }

    public function test_returns_unavailable_when_extension_not_provided(): void
    {
        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = []; // No extension

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('AI Agent not found', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_routes_to_sip_provider_with_legacy_config(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3001',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'provider' => 'vapi',
                'phone_number' => '+12125551234',
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        // Should contain Dial and Service elements for SIP routing
        $this->assertStringContainsString('<Dial>', $cxml);
        $this->assertStringContainsString('<Service', $cxml);
        $this->assertStringContainsString('provider="vapi"', $cxml);
        $this->assertStringContainsString('+12125551234', $cxml);
    }

    public function test_routes_to_sip_provider_with_explicit_protocol(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3001',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'sip',
                'provider' => 'retell',
                'phone_number' => '+19995551234',
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        $this->assertStringContainsString('<Dial>', $cxml);
        $this->assertStringContainsString('provider="retell"', $cxml);
        $this->assertStringContainsString('+19995551234', $cxml);
    }

    public function test_returns_unavailable_for_sip_provider_missing_phone_number(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3001',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'sip',
                'provider' => 'vapi',
                // Missing phone_number
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('AI Agent provider or phone number not configured', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_routes_to_websocket_provider(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                'provider' => 'deepdub',
                'bot_id' => '7Fn5qL8LCMkENwdrh9bhoW',
                'auth_token' => 'token123',
            ],
        ]);

        $request = Request::create('/route', 'POST', [
            'CallSid' => 'sess_abc123',
            'From' => '1001',
            'To' => '50000',
        ]);

        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        // Should contain Connect and Stream elements for WebSocket routing
        $this->assertStringContainsString('<Connect>', $cxml);
        $this->assertStringContainsString('<Stream', $cxml);
        $this->assertStringContainsString('wss://bot.deepdub.dev', $cxml);
        $this->assertStringContainsString('7Fn5qL8LCMkENwdrh9bhoW', $cxml);
        $this->assertStringContainsString('token123', $cxml);

        // Should include Cloudonix parameters
        $this->assertStringContainsString('session=sess_abc123', $cxml);
        $this->assertStringContainsString('from=1001', $cxml);
        $this->assertStringContainsString('to=50000', $cxml);
    }

    public function test_returns_unavailable_for_websocket_provider_without_provider_key(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                // Missing provider
                'bot_id' => '123',
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('AI Assistant provider not configured', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_returns_unavailable_for_invalid_websocket_provider(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                'provider' => 'invalid_provider',
                'bot_id' => '123',
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Invalid AI Assistant provider configuration', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_returns_unavailable_for_websocket_provider_missing_required_config(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                'provider' => 'deepdub',
                'bot_id' => '123',
                // Missing auth_token
            ],
        ]);

        $request = Request::create('/route', 'POST', [
            'CallSid' => 'sess_abc',
            'From' => '1001',
            'To' => '2002',
        ]);

        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('AI Assistant configuration error', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_extracts_cloudonix_parameters_from_request(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                'provider' => 'deepdub',
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ]);

        $request = Request::create('/route', 'POST', [
            'CallSid' => 'call_session_xyz',
            'From' => '+15551234567',
            'To' => '+15559876543',
        ]);

        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $cxml = $response->getContent();

        $this->assertStringContainsString('session=call_session_xyz', $cxml);
        $this->assertStringContainsString('from=%2B15551234567', $cxml); // URL encoded
        $this->assertStringContainsString('to=%2B15559876543', $cxml); // URL encoded
    }

    public function test_handles_missing_cloudonix_parameters_gracefully(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3002',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                'protocol' => 'websocket',
                'provider' => 'deepdub',
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ]);

        // Request without Cloudonix parameters
        $request = Request::create('/route', 'POST');

        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        // Should still generate CXML with empty parameter values
        $this->assertStringContainsString('<Connect>', $cxml);
        $this->assertStringContainsString('<Stream', $cxml);
    }

    public function test_backward_compatibility_defaults_to_sip_protocol(): void
    {
        // Extension without explicit protocol should default to SIP
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3001',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [
                // No protocol specified
                'provider' => 'vapi',
                'phone_number' => '+12125551234',
            ],
        ]);

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        // Should use SIP routing
        $this->assertStringContainsString('<Dial>', $cxml);
        $this->assertStringContainsString('<Service', $cxml);
        $this->assertStringNotContainsString('<Connect>', $cxml);
        $this->assertStringNotContainsString('<Stream', $cxml);
    }

    public function test_routes_using_service_url_column(): void
    {
        $extension = new Extension([
            'id' => 1,
            'extension_number' => '3001',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => [],
        ]);

        // Set service_url directly on the model
        $extension->service_url = 'sip:agent@example.com';
        $extension->service_token = 'bearer_token';
        $extension->service_params = ['param1' => 'value1'];

        $request = Request::create('/route', 'POST');
        $did = new DidNumber;
        $destination = ['extension' => $extension];

        $response = $this->strategy->route($request, $did, $destination);

        $this->assertEquals(200, $response->getStatusCode());
        $cxml = $response->getContent();

        $this->assertStringContainsString('<Dial>', $cxml);
        $this->assertStringContainsString('sip:agent@example.com', $cxml);
    }
}
