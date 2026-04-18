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
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live Simulation Test for Campaign CMP 2 Outbound Call
 *
 * This test simulates a complete outbound call flow:
 * 1. Get pending destination from campaign
 * 2. Call Cloudonix API to initiate outbound call
 * 3. Receive webhook callbacks
 * 4. Verify state updates
 */
class LiveCampaignCmp2SimulationTest extends TestCase
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
            'id' => 2,
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
     * Simulate complete outbound call from Campaign CMP 2
     */
    public function test_live_simulation_campaign_cmp2_outbound_call(): void
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║     LIVE SIMULATION: Campaign CMP 2 Outbound Call Flow          ║\n";
        echo "╚══════════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        // =====================================================================
        // STEP 1: Worker retrieves pending destination from campaign
        // =====================================================================
        echo "📋 STEP 1: Retrieving pending destination from Campaign CMP 2\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        $response = $this->getJson(
            "/api/v1/dialer/worker/campaigns/{$this->campaign->id}/destinations/pending?limit=1",
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);
        $destinations = $response->json('data');
        $this->assertCount(1, $destinations);

        $destinationData = $destinations[0];
        echo "✅ Retrieved destination:\n";
        echo "   • ID: {$destinationData['id']}\n";
        echo "   • Phone: {$destinationData['phone_number']}\n";
        echo "   • Status: {$destinationData['status']}\n";
        echo "   • Priority: {$destinationData['priority']}\n";
        echo "   • Dial Attempts: {$destinationData['dial_attempts']}\n";
        echo "\n";

        // =====================================================================
        // STEP 2: Worker initiates call via Cloudonix API
        // =====================================================================
        echo "📞 STEP 2: Initiating outbound call via Cloudonix API\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        // Prepare Cloudonix API request
        $cloudonixEndpoint = "https://api.cloudonix.io/calls/{$this->cloudonixSettings->domain_name}/application";
        $cloudonixToken = $this->cloudonixSettings->domain_requests_api_key;

        $cloudonixPayload = [
            'destination' => $this->destination->phone_number,
            'caller-id' => $this->campaign->caller_id,
            'application' => $this->campaign->routing_destination_id,
            'timeout' => $this->campaign->dial_timeout,
            'timeLimit' => $this->campaign->time_limit,
            'record' => $this->campaign->record_calls,
            'callback' => config('app.url').'/api/webhooks/cloudonix/dialer',
        ];
        $cloudonixPayload = array_filter($cloudonixPayload);

        echo "🌐 Cloudonix API Request:\n";
        echo "   • Endpoint: POST {$cloudonixEndpoint}\n";
        echo "   • Authorization: Bearer {$cloudonixToken}\n";
        echo "   • Content-Type: application/json\n";
        echo "\n   Request Body:\n";
        echo json_encode($cloudonixPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        echo "\n";

        // Fake the HTTP response from Cloudonix
        Http::fake([
            'api.cloudonix.io/calls/*' => Http::response([
                'domainId' => 3,
                'subscriberId' => 372,
                'destination' => $this->destination->phone_number,
                'direction' => 'outbound-api',
                'token' => 'call-token-'.uniqid(),
            ], 200),
        ]);

        // Make the actual API call
        $cloudonixResponse = Http::withHeaders([
            'Authorization' => 'Bearer '.$cloudonixToken,
            'Content-Type' => 'application/json',
        ])->post($cloudonixEndpoint, $cloudonixPayload);

        $this->assertTrue($cloudonixResponse->successful());
        $callData = $cloudonixResponse->json();

        echo "✅ Cloudonix API Response:\n";
        echo json_encode($callData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        echo "\n   Key Values:\n";
        echo "   • Call Token: {$callData['token']}\n";
        echo "   • Domain ID: {$callData['domainId']}\n";
        echo "   • Direction: {$callData['direction']}\n";
        echo "\n";

        // =====================================================================
        // STEP 3: Create call session record
        // =====================================================================
        echo "📝 STEP 3: Creating call session record\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        $initiateResponse = $this->postJson(
            '/api/v1/dialer/worker/calls/initiate',
            [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
                'phone_number' => $this->destination->phone_number,
                'worker_id' => 'worker-cmp2-001',
                'initiated_at' => now()->toIso8601String(),
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $initiateResponse->assertStatus(200);
        $sessionData = $initiateResponse->json('data');

        echo "✅ Call session created:\n";
        echo "   • Session ID: {$sessionData['session_id']}\n";
        echo "   • Call ID: {$sessionData['call_id']}\n";
        echo "\n";

        // Update session with Cloudonix call token
        $session = OrganizationScope::bypass(function () use ($sessionData, $callData) {
            $session = AutoDialerCallSession::find($sessionData['session_id']);
            $session?->update([
                'call_id' => $callData['token'],
                'session_token' => 'sess-cmp2-'.uniqid(),
            ]);

            return $session;
        });

        // Refresh to get updated values
        OrganizationScope::bypass(function () use ($session) {
            $session->refresh();
        });

        echo "   • Session Token: {$session->session_token}\n";
        echo "   • Cloudonix Call ID: {$session->call_id}\n";
        echo "   • Status: {$session->status}\n";
        echo "\n";

        // =====================================================================
        // STEP 4: Simulate Cloudonix webhooks
        // =====================================================================
        echo "📡 STEP 4: Simulating Cloudonix webhook callbacks\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        // Webhook 1: call.initiated
        echo "\n   📨 Webhook 1: call.initiated\n";
        $webhook1 = $this->sendWebhook('call.initiated', $session);
        echo '      Response: '.json_encode($webhook1)."\n";

        // Webhook 2: call.ringing
        echo "\n   📨 Webhook 2: call.ringing\n";
        $webhook2 = $this->sendWebhook('call.ringing', $session);
        echo '      Response: '.json_encode($webhook2)."\n";

        // Webhook 3: call.answered
        echo "\n   📨 Webhook 3: call.answered\n";
        $webhook3 = $this->sendWebhook('call.answered', $session);
        echo '      Response: '.json_encode($webhook3)."\n";

        // Webhook 4: call.completed
        echo "\n   📨 Webhook 4: call.completed (with disposition)\n";
        $webhook4 = $this->sendWebhook('call.completed', $session, [
            'disposition' => 'answered',
            'duration' => 45,
            'billsec' => 42,
        ]);
        echo '      Response: '.json_encode($webhook4)."\n";

        echo "\n";

        // =====================================================================
        // STEP 5: Verify final state
        // =====================================================================
        echo "✅ STEP 5: Verifying final state\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        OrganizationScope::bypass(function () use ($session) {
            $session->refresh();
        });
        $this->destination->refresh();
        $this->campaign->refresh();

        echo "📊 Call Session Final State:\n";
        echo "   • Status: {$session->status}\n";
        echo "   • Disposition: {$session->disposition}\n";
        echo "   • Duration: {$session->duration}s\n";
        echo "   • Billable Seconds: {$session->billsec}s\n";
        echo "\n";

        echo "📊 Destination Final State:\n";
        echo "   • Status: {$this->destination->status->value}\n";
        echo "   • Dial Attempts: {$this->destination->dial_attempts}\n";
        echo "   • Last Disposition: {$this->destination->last_disposition}\n";
        echo "\n";

        echo "📊 Campaign CMP 2 Statistics:\n";
        echo "   • Total Destinations: {$this->campaign->total_destinations}\n";
        echo "   • Completed Calls: {$this->campaign->completed_calls}\n";
        echo "   • Failed Calls: {$this->campaign->failed_calls}\n";
        echo "   • Pending Calls: {$this->campaign->pending_calls}\n";
        echo "\n";

        // =====================================================================
        // Assertions
        // =====================================================================
        $this->assertEquals('completed', $session->status);
        $this->assertEquals('answered', $session->disposition);
        $this->assertEquals(45, $session->duration);
        $this->assertEquals(DestinationStatus::COMPLETED, $this->destination->status);

        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║  ✅ SIMULATION COMPLETE - Campaign CMP 2 Outbound Call Success   ║\n";
        echo "╚══════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    /**
     * Helper to send webhook and return response
     */
    private function sendWebhook(string $eventType, AutoDialerCallSession $session, array $extraData = []): array
    {
        $webhookPayload = array_merge([
            'type' => $eventType,
            'call_id' => $session->call_id,
            'session_token' => $session->session_token,
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
                'worker_id' => 'worker-cmp2-001',
            ],
            'domain_uuid' => $this->cloudonixSettings->domain_uuid,
            'owner' => [
                'domain' => [
                    'uuid' => $this->cloudonixSettings->domain_uuid,
                    'name' => $this->cloudonixSettings->domain_name,
                ],
            ],
        ], $extraData);

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $webhookPayload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        // Debug: Check session state after webhook
        $updatedSession = OrganizationScope::bypass(function () use ($session) {
            return AutoDialerCallSession::find($session->id);
        });

        echo "      [Debug] Session status after webhook: {$updatedSession->status}\n";

        return $response->json();
    }
}
