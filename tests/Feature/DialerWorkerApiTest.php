<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ListStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Dialer Worker API Feature Tests
 *
 * Tests the complete worker API for auto dialer campaign execution.
 * These endpoints are used by the Go-based worker service.
 */
class DialerWorkerApiTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_TOKEN = 'test-worker-token-123';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.dialer_worker.token', self::WORKER_TOKEN);

        $this->organization = Organization::factory()->create();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: Make authenticated request to worker API
     */
    private function workerRequest(string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::WORKER_TOKEN,
            'Accept' => 'application/json',
        ])->json($method, '/api/v1/dialer/worker'.$uri, $data);
    }

    /**
     * Helper: Create a campaign with an assigned list and destinations
     */
    private function createCampaignWithDestinations(
        array $campaignAttrs = [],
        int $destinationCount = 1,
        array $destinationAttrs = []
    ): array {
        $campaign = AutoDialerCampaign::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
        ], $campaignAttrs));

        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'used_by_campaign_id' => $campaign->id,
            'status' => ListStatus::IN_USE,
            'used_at' => now(),
        ]);

        $destinations = [];
        foreach (range(1, $destinationCount) as $i) {
            $destinations[] = AutoDialerDestination::factory()->create(array_merge([
                'organization_id' => $this->organization->id,
                'list_id' => $list->id,
            ], $destinationAttrs));
        }

        return [$campaign, $list, $destinations];
    }

    // ═══════════════════════════════════════════════════════════
    // HEALTH CHECK TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_health_check_returns_ok(): void
    {
        $response = $this->workerRequest('GET', '/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'healthy',
            ]);
    }

    public function test_health_check_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/dialer/worker/health');

        $response->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════════
    // ACTIVE CAMPAIGNS TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_get_active_campaigns_returns_running_campaigns(): void
    {
        // Create active campaign with current schedule (using isRunnable-compatible fields)
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'days_active' => [strtolower(now()->format('l'))],
            'start_time' => 0,
            'end_time' => 23,
        ]);

        $response = $this->workerRequest('GET', '/campaigns/active');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_active_campaigns_excludes_paused_campaigns(): void
    {
        $this->createCampaignWithDestinations([
            'status' => CampaignStatus::PAUSED,
        ]);

        $response = $this->workerRequest('GET', '/campaigns/active');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_get_active_campaigns_excludes_outside_schedule(): void
    {
        // Create campaign with schedule that doesn't include current time
        $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'schedule' => [
                'monday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'tuesday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'wednesday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'thursday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'friday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'saturday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
                'sunday' => [
                    'enabled' => false,
                    'time_ranges' => [],
                ],
            ],
        ]);

        $response = $this->workerRequest('GET', '/campaigns/active');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ═══════════════════════════════════════════════════════════
    // PENDING DESTINATIONS TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_get_pending_destinations_returns_pending_items(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 3,
        ], 2);

        // Get or create a list for this campaign
        $list = AutoDialerList::where('campaign_id', $campaign->id)->first()
            ?? AutoDialerList::factory()->create([
                'organization_id' => $this->organization->id,
                'campaign_id' => $campaign->id,
            ]);

        // Create destinations with different statuses
        AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
            'status' => DestinationStatus::PENDING,
            'phone_number' => '+1234567890',
        ]);

        $response = $this->workerRequest('GET', "/campaigns/{$campaign->id}/destinations/pending");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [],
                'meta' => ['total', 'limit', 'offset'],
            ]);
    }

    public function test_get_pending_destinations_limits_count(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ], 5, [
            'status' => DestinationStatus::PENDING,
        ]);

        $response = $this->workerRequest('GET', "/campaigns/{$campaign->id}/destinations/pending?limit=3");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertLessThanOrEqual(3, count($data));
    }

    public function test_get_pending_destinations_for_campaign_without_lists(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->workerRequest('GET', "/campaigns/{$campaign->id}/destinations/pending");

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // ═══════════════════════════════════════════════════════════
    // RETRY DESTINATIONS TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_get_retry_destinations_returns_retry_eligible_items(): void
    {
        [$campaign, $list] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 3,
        ], 1);

        // Create failed destination with past retry time
        AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
            'status' => DestinationStatus::FAILED,
            'phone_number' => '+1234567890',
            'last_disposition' => 'busy',
            'dial_attempts' => 1,
            'next_retry_at' => now()->subMinute(),
        ]);

        // Create failed destination with future retry time (should not be returned)
        AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
            'status' => DestinationStatus::FAILED,
            'phone_number' => '+1987654321',
            'last_disposition' => 'busy',
            'dial_attempts' => 1,
            'next_retry_at' => now()->addHour(),
        ]);

        $response = $this->workerRequest('GET', "/campaigns/{$campaign->id}/destinations/retry");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('+1234567890', $data[0]['phone_number']);
    }

    public function test_get_retry_destinations_exceeds_max_attempts(): void
    {
        [$campaign, $list] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 2,
        ]);

        // Create destination that has exceeded max attempts
        AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
            'status' => DestinationStatus::FAILED,
            'last_disposition' => 'busy',
            'dial_attempts' => 3, // Exceeds max_dial_attempts of 2
            'next_retry_at' => now()->subMinute(),
        ]);

        $response = $this->workerRequest('GET', "/campaigns/{$campaign->id}/destinations/retry");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ═══════════════════════════════════════════════════════════
    // CALL INITIATION TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_initiate_call_creates_session(): void
    {
        [$campaign, $list, $destinations] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'caller_id' => '+15551234567',
            'routing_destination_type' => 'ai_assistant',
            'routing_destination_id' => 1,
        ], 1, [
            'status' => DestinationStatus::PENDING,
            'phone_number' => '+1234567890',
        ]);

        $destination = $destinations[0];

        $response = $this->workerRequest('POST', '/calls/initiate', [
            'destination_id' => $destination->id,
            'campaign_id' => $campaign->id,
            'phone_number' => $destination->phone_number,
            'worker_id' => 'worker-001',
            'initiated_at' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'session_id',
                    'call_id',
                ],
            ]);

        // Verify destination status was updated (bypass scope)
        $destinationStatus = OrganizationScope::bypass(fn () => AutoDialerDestination::find($destination->id)?->status);
        $this->assertEquals(DestinationStatus::DIALING, $destinationStatus);

        // Verify session was created (bypass scope)
        $session = OrganizationScope::bypass(
            fn () => AutoDialerCallSession::where('destination_id', $destination->id)->first()
        );
        $this->assertNotNull($session);
        $this->assertEquals('worker-001', $session->worker_id);
    }

    public function test_initiate_call_returns_404_for_invalid_destination(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->workerRequest('POST', '/calls/initiate', [
            'destination_id' => 99999, // Non-existent
            'campaign_id' => $campaign->id,
            'phone_number' => '+1234567890',
            'worker_id' => 'worker-001',
            'initiated_at' => now()->toIso8601String(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_id']);
    }

    public function test_initiate_call_validates_required_fields(): void
    {
        $response = $this->workerRequest('POST', '/calls/initiate', [
            // Missing required fields
            'campaign_id' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_id', 'phone_number', 'worker_id', 'initiated_at']);
    }

    // ═══════════════════════════════════════════════════════════
    // CALL STATUS UPDATE TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_update_call_status_to_completed(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'completed_calls' => 0,
        ], 1, [
            'status' => DestinationStatus::DIALING,
        ]);

        $destinationId = OrganizationScope::bypass(function () {
            return AutoDialerDestination::where('status', DestinationStatus::DIALING)->first()?->id;
        });
        $this->assertNotNull($destinationId, 'Destination should exist');

        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destinationId,
            'status' => 'initiated',
        ]);

        $response = $this->workerRequest('PATCH', "/calls/{$session->id}/status", [
            'status' => 'completed',
            'disposition' => 'answered',
            'duration' => 120,
            'billsec' => 115,
            'recording_url' => 'https://recordings.example.com/call-123.mp3',
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();

        // Verify session was updated
        $sessionStatus = OrganizationScope::bypass(fn () => $session->fresh()?->status);
        $this->assertEquals('completed', $sessionStatus);

        // Verify destination was updated
        $destinationStatus = OrganizationScope::bypass(function () use ($destinationId) {
            return AutoDialerDestination::find($destinationId)?->status;
        });
        $this->assertEquals(DestinationStatus::COMPLETED, $destinationStatus);
    }

    public function test_update_call_status_to_failed_with_retryable_disposition(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 3,
        ], 1, [
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => 1,
        ]);

        $destinationId = OrganizationScope::bypass(function () {
            return AutoDialerDestination::where('status', DestinationStatus::DIALING)->first()?->id;
        });
        $this->assertNotNull($destinationId, 'Destination should exist');

        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destinationId,
            'status' => 'initiated',
        ]);

        $response = $this->workerRequest('PATCH', "/calls/{$session->id}/status", [
            'status' => 'failed',
            'disposition' => 'busy',
            'duration' => 0,
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();

        // Verify destination is set for retry
        $nextRetry = OrganizationScope::bypass(function () use ($destinationId) {
            return AutoDialerDestination::find($destinationId)?->next_retry_at;
        });
        $this->assertNotNull($nextRetry);
    }

    public function test_update_call_status_to_failed_with_non_retryable_disposition(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ], 1, [
            'status' => DestinationStatus::DIALING,
        ]);

        $destinationId = OrganizationScope::bypass(function () {
            return AutoDialerDestination::where('status', DestinationStatus::DIALING)->first()?->id;
        });
        $this->assertNotNull($destinationId, 'Destination should exist');

        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destinationId,
            'status' => 'initiated',
        ]);

        $response = $this->workerRequest('PATCH', "/calls/{$session->id}/status", [
            'status' => 'failed',
            'disposition' => 'congestion',
            'duration' => 0,
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();

        // Verify destination is marked as failed (no retry scheduled)
        $destinationData = OrganizationScope::bypass(function () use ($destinationId) {
            $dest = AutoDialerDestination::find($destinationId);

            return [
                'status' => $dest?->status,
                'next_retry_at' => $dest?->next_retry_at,
            ];
        });
        $this->assertEquals(DestinationStatus::FAILED, $destinationData['status']);
        $this->assertNull($destinationData['next_retry_at']);
    }

    // ═══════════════════════════════════════════════════════════
    // PAUSE CAMPAIGN TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_pause_campaign_sets_status_to_paused(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->workerRequest('POST', "/campaigns/{$campaign->id}/pause", [
            'reason' => 'High failure rate detected',
            'paused_by' => 'worker-001',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Campaign paused',
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => 'paused',
                ],
            ]);

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::PAUSED, $campaign->status);
    }

    public function test_pause_non_existent_campaign_returns_404(): void
    {
        $response = $this->workerRequest('POST', '/campaigns/99999/pause', [
            'reason' => 'Test',
            'paused_by' => 'worker-001',
        ]);

        $response->assertNotFound();
    }

    public function test_pause_campaign_validates_required_fields(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->workerRequest('POST', "/campaigns/{$campaign->id}/pause", [
            // Missing reason and paused_by
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'paused_by']);
    }

    // ═══════════════════════════════════════════════════════════
    // STATE PERSISTENCE TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_persist_state_stores_in_cache(): void
    {
        $state = [
            'worker_id' => 'worker-001',
            'active_calls' => [
                ['destination_id' => 1, 'session_id' => 'sess-123'],
            ],
            'retry_queue' => [],
            'campaign_states' => [],
            'last_updated' => now()->toIso8601String(),
        ];

        $response = $this->workerRequest('POST', '/state/persist', $state);

        $response->assertOk()
            ->assertJson([
                'message' => 'State persisted successfully',
            ]);
    }

    public function test_get_state_retrieves_from_cache(): void
    {
        $state = [
            'worker_id' => 'worker-001',
            'active_calls' => [],
            'retry_queue' => [],
            'campaign_states' => [],
            'last_updated' => now()->toIso8601String(),
        ];

        // First persist the state
        $this->workerRequest('POST', '/state/persist', $state);

        // Then retrieve it
        $response = $this->workerRequest('GET', '/state/worker-001');

        $response->assertOk()
            ->assertJson([
                'data' => $state,
            ]);
    }

    public function test_get_state_returns_404_for_missing_state(): void
    {
        $response = $this->workerRequest('GET', '/state/non-existent-worker');

        $response->assertNotFound()
            ->assertJson([
                'error' => 'State not found',
            ]);
    }

    public function test_persist_state_validates_required_fields(): void
    {
        $response = $this->workerRequest('POST', '/state/persist', [
            // Missing required fields
            'worker_id' => 'worker-001',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['active_calls', 'retry_queue', 'campaign_states', 'last_updated']);
    }

    // ═══════════════════════════════════════════════════════════
    // AUTHENTICATION TESTS
    // ═══════════════════════════════════════════════════════════

    public function test_all_endpoints_require_valid_token(): void
    {
        [$campaign] = $this->createCampaignWithDestinations([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $endpoints = [
            ['GET', '/health'],
            ['GET', '/campaigns/active'],
            ['GET', "/campaigns/{$campaign->id}/destinations/pending"],
            ['GET', "/campaigns/{$campaign->id}/destinations/retry"],
            ['POST', '/calls/initiate'],
            ['POST', "/campaigns/{$campaign->id}/pause"],
            ['POST', '/state/persist'],
            ['GET', '/state/worker-001'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            // Test without token
            $response = $this->json($method, '/api/v1/dialer/worker'.$uri);
            $response->assertUnauthorized();

            // Test with invalid token
            $response = $this->withHeaders([
                'Authorization' => 'Bearer invalid-token',
            ])->json($method, '/api/v1/dialer/worker'.$uri);
            $response->assertUnauthorized();
        }
    }

    public function test_secondary_token_is_accepted(): void
    {
        // Set both tokens for rotation testing
        Config::set('services.dialer_worker.token', 'primary-token');
        Config::set('services.dialer_worker.token_secondary', 'secondary-token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer secondary-token',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dialer/worker/health');

        $response->assertOk();
    }
}
