<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AutoDialerCxmlMetadataTest extends TestCase
{
    use RefreshDatabase;

    private AutoDialerCloudonixService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutoDialerCloudonixService(
            Mockery::mock(OutboundRoutingService::class),
            Mockery::mock(PhoneNumberService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sip_ai_assistant_cxml_includes_metadata_headers(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
        $this->assertStringContainsString('<Header name="X-key2" value="value2"/>', $cxml);
        $this->assertStringContainsString('<Service provider="retell">+12127773456</Service>', $cxml);
    }

    public function test_websocket_ai_assistant_cxml_includes_metadata_parameters(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'deepdub',
            'protocol' => 'websocket',
            'configuration' => [
                'bot_id' => 'bot123',
                'auth_token' => 'token456',
            ],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Parameter name="key" value="value"/>', $cxml);
        $this->assertStringContainsString('<Parameter name="key2" value="value2"/>', $cxml);
    }

    public function test_dummy_ai_assistant_cxml_includes_metadata_comments(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'dummy_ai',
            'protocol' => 'dummy',
            'configuration' => [],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value', 'key2' => 'value2'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<!-- metadata key="key" value="value" -->', $cxml);
        $this->assertStringContainsString('<!-- metadata key="key2" value="value2" -->', $cxml);
    }

    public function test_ai_load_balancer_cxml_includes_metadata_for_selected_assistant(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $organization->id,
            'strategy' => 'priority',
            'follow_through' => false,
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_LOAD_BALANCER,
            'routing_destination_id' => $loadBalancer->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => ['key' => 'value'],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
    }

    public function test_empty_metadata_omits_comments_headers_parameters(): void
    {
        $organization = Organization::factory()->create();
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
        ]);
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $aiAssistant->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'metadata' => [],
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign, $destination);

        $this->assertStringNotContainsString('<Header', $cxml);
    }
}
