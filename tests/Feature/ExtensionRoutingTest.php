<?php

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Models\AiAssistant;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class ExtensionRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $organization;

    protected VoiceRoutingManager $routingManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::factory()->create(['status' => 'active']);

        // Create Cloudonix settings
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        // Get the routing manager from the container
        $this->routingManager = app(VoiceRoutingManager::class);

        $this->setupExtensions();
    }

    private function setupExtensions(): void
    {
        // Create conference room
        $conferenceRoom = ConferenceRoom::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Conference',
            'pin' => '1234',
        ]);

        // Create ring group with members
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Sales Team',
            'strategy' => 'simultaneous',
            'timeout' => 30,
        ]);

        // Add ring group members using the HasMany relation
        $member1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);
        $member2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1002',
            'type' => ExtensionType::USER,
        ]);

        $ringGroup->members()->create(['extension_id' => $member1->id, 'priority' => 1]);
        $ringGroup->members()->create(['extension_id' => $member2->id, 'priority' => 2]);

        // Create IVR menu
        $ivrMenu = IvrMenu::factory()->active()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Main Menu',
            'tts_text' => 'Welcome to our company. Press 1 for sales.',
            'max_turns' => 3,
        ]);

        // Create AI assistant for the AI extension
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'protocol' => 'sip',
        ]);

        // Create target extension for the forward extension
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1003',
            'type' => ExtensionType::USER,
        ]);

        // Create extensions
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '3000',
            'type' => ExtensionType::CONFERENCE,
            'configuration' => ['conference_room_id' => $conferenceRoom->id],
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '3001',
            'type' => ExtensionType::RING_GROUP,
            'configuration' => ['ring_group_id' => $ringGroup->id],
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '3002',
            'type' => ExtensionType::IVR,
            'configuration' => ['ivr_id' => $ivrMenu->id],
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'ai_assistant_id' => $aiAssistant->id,
            'extension_number' => '3003',
            'type' => ExtensionType::AI_ASSISTANT,
            'service_url' => 'https://ai.example.com',
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '3004',
            'type' => ExtensionType::FORWARD,
            'configuration' => ['forward_to' => '1003'],
        ]);
    }

    private function createRequest(string $to): Request
    {
        $request = new Request;
        $request->merge([
            'To' => $to,
            'From' => '1001',
            'CallSid' => 'test-call-'.$to,
            'Direction' => 'subscriber',
            '_organization_id' => $this->organization->id,
        ]);

        return $request;
    }

    public function test_conference_room_routing(): void
    {
        $request = $this->createRequest('3000');

        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('<Conference ', $content);
        $this->assertStringContainsString('conf_', $content); // Conference room name/ID
    }

    public function test_ring_group_routing(): void
    {
        $request = $this->createRequest('3001');

        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('<Number>', $content); // Should dial ring group members
    }

    public function test_ivr_menu_routing(): void
    {
        $request = $this->createRequest('3002');

        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('<Gather ', $content);
        $this->assertStringContainsString('Welcome to our company', $content);
    }

    public function test_ai_assistant_routing(): void
    {
        $request = $this->createRequest('3003');

        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('<Service>', $content);
        $this->assertStringContainsString('ai.example.com', $content);
    }

    public function test_forward_routing(): void
    {
        $request = $this->createRequest('3004');

        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1003', $content);
    }
}
