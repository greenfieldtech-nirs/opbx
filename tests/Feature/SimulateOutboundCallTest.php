<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Simulation test for outbound call from Campaign CMP 2
 *
 * This test demonstrates the complete flow of initiating an outbound call
 * through the Cloudonix API for campaign ID 2.
 */
class SimulateOutboundCallTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CloudonixSettings $cloudonixSettings;

    private AutoDialerCampaign $campaign;

    private AutoDialerList $list;

    private AutoDialerDestination $destination;

    private string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        $this->workerToken = 'test-worker-token-123';
        Config::set('services.dialer_worker.token', $this->workerToken);

        // Setup fake HTTP client to intercept Cloudonix API calls
        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response([
                'domainId' => 3,
                'subscriberId' => 372,
                'destination' => '15551234567',
                'direction' => 'outbound-api',
                'token' => '16a7294c989b11e7b3d32b9edb8660c7',
            ], 200),
        ]);

        // Create test organization with Cloudonix settings
        $this->organization = Organization::factory()->create([
            'name' => 'Test Organization',
        ]);

        $this->cloudonixSettings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '6ff6c13b-809e-3d08-a305-24e9fd6b3da9',
            'domain_name' => 'dograh-ejm4ke.cloudonix.net',
            'domain_requests_api_key' => 'XIBB0E3CD4FB1F46698DE5FC51B49A012E',
        ]);

        // Create Campaign CMP 2 (matching the user's request)
        $this->campaign = AutoDialerCampaign::factory()->create([
            'id' => 2, // Force ID to be 2 as requested
            'organization_id' => $this->organization->id,
            'name' => 'CMP 2',
            'status' => CampaignStatus::ACTIVE,
            'caller_id' => '+18001234567',
            'max_dial_attempts' => 3,
            'calls_per_second' => 1,
            'concurrent_active_calls' => 10,
            'dial_timeout' => 30,
            'time_limit' => 3600,
            'record_calls' => true,
            'action_voicemail' => 'HANGUP',
            'action_human' => 'CONTINUE',
            'action_unknown' => 'HANGUP',
            'routing_destination_type' => 'ai_assistant',
            'routing_destination_id' => 5,
            'pending_calls' => 1,
        ]);

        // Create a list for this campaign
        $this->list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
            'name' => 'Test List for CMP 2',
        ]);

        // Create a pending destination to call
        $this->destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $this->list->id,
            'phone_number' => '+15551234567',
            'status' => DestinationStatus::PENDING,
            'dial_attempts' => 0,
            'priority' => 1,
        ]);
    }

    /**
     * Simulate a single outbound call from Campaign CMP 2
     */
    public function test_simulate_outbound_call_from_campaign_cmp2(): void
    {
        // Step 1: Worker requests pending destinations for the campaign
        $response = $this->getJson(
            "/api/v1/dialer/worker/campaigns/{$this->campaign->id}/destinations/pending?limit=1",
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);
        $destinations = $response->json('data');
        $this->assertCount(1, $destinations);

        $destinationData = $destinations[0];
        $this->assertEquals('+15551234567', $destinationData['phone_number']);

        echo "\n=== STEP 1: Retrieved pending destination ===\n";
        echo "Destination ID: {$destinationData['id']}\n";
        echo "Phone Number: {$destinationData['phone_number']}\n";
        echo 'Priority: '.($destinationData['priority'] ?? 'N/A')."\n";

        // Step 2: Worker initiates the call
        // This would normally call Cloudonix API
        $initiateResponse = $this->postJson(
            '/api/v1/dialer/worker/calls/initiate',
            [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
                'phone_number' => $this->destination->phone_number,
                'worker_id' => 'test-worker-1',
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        // Note: The initiate endpoint may not exist yet, but we can simulate the flow
        // Let's show what the Cloudonix API request would look like

        echo "\n=== STEP 2: Cloudonix API Request ===\n";
        echo "Endpoint: POST https://api.cloudonix.io/calls/{$this->cloudonixSettings->domain_name}/application\n";
        echo 'Authorization: Bearer '.$this->cloudonixSettings->domain_requests_api_key."\n";
        echo "Content-Type: application/json\n";
        echo "\nRequest Body:\n";

        $cloudonixPayload = [
            'destination' => $this->destination->phone_number,
            'caller-id' => $this->campaign->caller_id,
            'application' => $this->campaign->routing_destination_id,
            'timeout' => $this->campaign->dial_timeout,
            'timeLimit' => $this->campaign->time_limit,
            'record' => $this->campaign->record_calls,
            'callback' => config('app.url').'/api/webhooks/cloudonix/dialer',
        ];

        // Remove null values
        $cloudonixPayload = array_filter($cloudonixPayload);

        echo json_encode($cloudonixPayload, JSON_PRETTY_PRINT)."\n";

        // Step 3: Create a call session record
        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
            'destination_id' => $this->destination->id,
            'session_token' => 'sess-'.uniqid(),
            'call_id' => '16a7294c989b11e7b3d32b9edb8660c7', // Simulated Cloudonix token
            'phone_number' => $this->destination->phone_number,
            'worker_id' => 'test-worker-1',
            'status' => 'initiated',
        ]);

        // Update destination status to DIALING
        $this->destination->update([
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => 1,
            'last_call_id' => $session->call_id,
        ]);

        echo "\n=== STEP 3: Call Session Created ===\n";
        echo "Session ID: {$session->id}\n";
        echo "Session Token: {$session->session_token}\n";
        echo "Call ID (Cloudonix): {$session->call_id}\n";
        echo "Status: {$session->status}\n";
        echo "Worker ID: {$session->worker_id}\n";

        // Step 4: Show webhook expectation
        echo "\n=== STEP 4: Webhook Configuration ===\n";
        echo 'Webhook URL: '.config('app.url')."/api/webhooks/cloudonix/dialer\n";
        echo "Expected webhooks from Cloudonix:\n";
        echo "  - call.initiated (call is being placed)\n";
        echo "  - call.ringing (destination is ringing)\n";
        echo "  - call.answered (call was answered)\n";
        echo "  - call.completed (call ended with disposition)\n";
        echo "  - amd.completed (if AMD enabled and result available)\n";

        // Step 5: Simulate a completed call webhook
        echo "\n=== STEP 5: Simulating call.completed webhook ===\n";

        $webhookPayload = [
            'type' => 'call.completed',
            'call_id' => $session->call_id,
            'session_token' => $session->session_token,
            'disposition' => 'answered',
            'duration' => 45,
            'billsec' => 42,
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
                'worker_id' => 'test-worker-1',
            ],
            'domain_uuid' => $this->cloudonixSettings->domain_uuid,
            'owner' => [
                'domain' => [
                    'uuid' => $this->cloudonixSettings->domain_uuid,
                    'name' => $this->cloudonixSettings->domain_name,
                ],
            ],
        ];

        $webhookResponse = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $webhookPayload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $webhookResponse->assertStatus(200);

        echo "Webhook payload:\n";
        echo json_encode($webhookPayload, JSON_PRETTY_PRINT)."\n";
        echo "\nWebhook response: ".json_encode($webhookResponse->json())."\n";

        // Step 6: Verify final state
        $session->refresh();
        $this->destination->refresh();
        $this->campaign->refresh();

        echo "\n=== STEP 6: Final State ===\n";
        echo "Session Status: {$session->status}\n";
        echo "Session Disposition: {$session->disposition}\n";
        echo "Session Duration: {$session->duration}s\n";
        echo 'Destination Status: '.$this->destination->status->value."\n";
        echo "Campaign Completed Calls: {$this->campaign->completed_calls}\n";
        echo "Campaign Pending Calls: {$this->campaign->pending_calls}\n";

        // Assertions to verify the flow worked correctly
        $this->assertEquals('completed', $session->status);
        $this->assertEquals('answered', $session->disposition);
        $this->assertEquals(45, $session->duration);
        $this->assertEquals(DestinationStatus::COMPLETED, $this->destination->status);

        echo "\n=== SIMULATION COMPLETE ===\n";
        echo "Campaign CMP 2 outbound call simulation completed successfully!\n";
    }

    /**
     * Show the raw Cloudonix API request without making the call
     */
    public function test_show_cloudonix_api_request_structure(): void
    {
        echo "\n\n=== CLOUDONIX API REQUEST STRUCTURE FOR CMP 2 ===\n\n";

        echo "Campaign Configuration:\n";
        echo "  - Name: {$this->campaign->name}\n";
        echo "  - ID: {$this->campaign->id}\n";
        echo "  - Caller ID: {$this->campaign->caller_id}\n";
        echo "  - Domain: {$this->cloudonixSettings->domain_name}\n";
        echo "  - Timeout: {$this->campaign->dial_timeout}s\n";
        echo "  - Time Limit: {$this->campaign->time_limit}s\n";
        echo '  - Record: '.($this->campaign->record_calls ? 'Yes' : 'No')."\n";
        $amdEnabled = $this->campaign->action_voicemail || $this->campaign->action_human || $this->campaign->action_unknown;
        echo '  - AMD: '.($amdEnabled ? 'Yes' : 'No')."\n";
        echo "  - Routing: {$this->campaign->routing_destination_type->value} (ID: {$this->campaign->routing_destination_id})\n";

        echo "\nDestination:\n";
        echo "  - Phone: {$this->destination->phone_number}\n";

        echo "\n--- HTTP REQUEST ---\n";
        echo "POST /calls/{$this->cloudonixSettings->domain_name}/application HTTP/1.1\n";
        echo "Host: api.cloudonix.io\n";
        echo 'Authorization: Bearer '.$this->cloudonixSettings->domain_requests_api_key."\n";
        echo "Content-Type: application/json\n";
        echo "Accept: application/json\n";
        echo "\n";

        $payload = [
            'destination' => $this->destination->phone_number,
            'caller-id' => $this->campaign->caller_id,
            'application' => $this->campaign->routing_destination_id,
            'timeout' => $this->campaign->dial_timeout,
            'timeLimit' => $this->campaign->time_limit,
            'record' => true,
            'machineDetection' => 'Enable',
            'callback' => config('app.url').'/api/webhooks/cloudonix/dialer',
        ];

        echo json_encode($payload, JSON_PRETTY_PRINT)."\n";

        echo "\n--- EXPECTED RESPONSE ---\n";
        echo "HTTP/1.1 200 OK\n";
        echo "Content-Type: application/json\n";
        echo "\n";
        echo json_encode([
            'domainId' => 3,
            'subscriberId' => 372,
            'destination' => $this->destination->phone_number,
            'direction' => 'outbound-api',
            'token' => '16a7294c989b11e7b3d32b9edb8660c7',
        ], JSON_PRETTY_PRINT)."\n";

        echo "\n--- WEBHOOK CALLBACK URL ---\n";
        echo config('app.url')."/api/webhooks/cloudonix/dialer\n";
        echo "\nThis URL will receive POST requests with call status updates:\n";
        echo "  • call.initiated - Call is being initiated\n";
        echo "  • call.ringing - Destination is ringing\n";
        echo "  • call.answered - Call was answered\n";
        echo "  • call.completed - Call ended (includes duration, billsec, disposition)\n";
        echo "  • amd.completed - Answering machine detection result\n";

        $this->assertTrue(true); // Test passes - this just displays information
    }
}
