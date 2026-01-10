<?php

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    private function setupExtensions()
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

        // Add ring group members
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

        $ringGroup->members()->attach([$member1->id, $member2->id]);

        // Create IVR menu
        $ivrMenu = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Main Menu',
            'tts_text' => 'Welcome to our company. Press 1 for sales.',
            'max_turns' => 3,
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
            'extension_number' => '3003',
            'type' => ExtensionType::AI_ASSISTANT,
            'configuration' => ['service_url' => 'https://ai.example.com'],
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '3004',
            'type' => ExtensionType::FORWARD,
            'configuration' => ['forward_to' => '555-123-4567'],
        ]);
    }

    public function test_conference_room_routing()
    {
        $request = new Request();
        $request->merge([
            'To' => '3000',
            'From' => '1001',
            'CallSid' => 'test-call-3000',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContains('<Conference>', $content);
        $this->assertStringContains('conference-', $content); // Conference room name/ID
    }

    public function test_ring_group_routing()
    {
        $request = new Request();
        $request->merge([
            'To' => '3001',
            'From' => '1001',
            'CallSid' => 'test-call-3001',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContains('<Dial>', $content);
        $this->assertStringContains('<Number>', $content); // Should dial ring group members
    }

    public function test_ivr_menu_routing()
    {
        $request = new Request();
        $request->merge([
            'To' => '3002',
            'From' => '1001',
            'CallSid' => 'test-call-3002',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContains('<Gather>', $content);
        $this->assertStringContains('Welcome to our company', $content);
    }

    public function test_ai_assistant_routing()
    {
        $request = new Request();
        $request->merge([
            'To' => '3003',
            'From' => '1001',
            'CallSid' => 'test-call-3003',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContains('<Service>', $content);
        $this->assertStringContains('ai.example.com', $content);
    }

    public function test_forward_routing()
    {
        $request = new Request();
        $request->merge([
            'To' => '3004',
            'From' => '1001',
            'CallSid' => 'test-call-3004',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertStringContains('<Dial>', $content);
        $this->assertStringContains('555-123-4567', $content);
    }
}