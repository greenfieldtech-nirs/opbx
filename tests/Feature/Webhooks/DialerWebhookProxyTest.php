<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for DialerWebhookProxyController
 *
 * Tests the webhook handling for auto-dialer campaigns,
 * including call status updates and retry logic.
 */
class DialerWebhookProxyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CloudonixSettings $cloudonixSettings;

    private AutoDialerCampaign $campaign;

    private AutoDialerList $list;

    private AutoDialerDestination $destination;

    private AutoDialerCallSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization with Cloudonix settings
        $this->organization = Organization::factory()->create();
        $this->cloudonixSettings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => '6ff6c13b-809e-3d08-a305-24e9fd6b3da9',
            'domain_name' => 'dograh-ejm4ke.cloudonix.net',
            'domain_requests_api_key' => 'XIBB0E3CD4FB1F46698DE5FC51B49A012E',
        ]);

        // Create campaign
        $this->campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 3,
        ]);

        // Create list
        $this->list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
        ]);

        // Create destination
        $this->destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $this->list->id,
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => 1,
        ]);

        // Create call session
        $this->session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
            'destination_id' => $this->destination->id,
            'status' => 'initiated',
            'session_token' => 'test-session-token-123',
            'call_id' => 'test-call-id-456',
        ]);
    }

    /**
     * Test that webhooks require valid authentication.
     */
    public function test_webhook_requires_authentication(): void
    {
        $response = $this->postJson('/api/webhooks/cloudonix/dialer', [
            'type' => 'call.completed',
            'call_id' => 'test-call-id-456',
            'domain' => $this->cloudonixSettings->domain_name,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test handling call initiated webhook.
     */
    public function test_call_initiated_webhook_updates_session_status(): void
    {
        $payload = [
            'type' => 'call.initiated',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->session = $this->session->fresh();
        $this->assertEquals('ringing', $this->session->status);
    }

    /**
     * Test handling call answered webhook.
     */
    public function test_call_answered_webhook_updates_session_and_destination(): void
    {
        $payload = [
            'type' => 'call.answered',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->session = $this->session->fresh();
        $this->destination = $this->destination->fresh();

        $this->assertEquals('answered', $this->session->status);
        $this->assertEquals(DestinationStatus::CONNECTED, $this->destination->status);
    }

    /**
     * Test handling call completed webhook with answered disposition.
     */
    public function test_call_completed_answered_marks_destination_completed(): void
    {
        $payload = [
            'type' => 'call.completed',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'disposition' => 'answered',
            'duration' => 45,
            'billsec' => 42,
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->session = $this->session->fresh();
        $this->destination = $this->destination->fresh();

        $this->assertEquals('completed', $this->session->status);
        $this->assertEquals(DestinationStatus::COMPLETED, $this->destination->status);
        $this->assertEquals('answered', $this->destination->last_disposition);
        $this->assertEquals(45, $this->session->duration);
        $this->assertEquals(42, $this->session->billsec);
    }

    /**
     * Test busy disposition schedules retry.
     */
    public function test_busy_disposition_schedules_retry(): void
    {
        $payload = [
            'type' => 'call.busy',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        $this->destination = $this->destination->fresh();

        $this->assertEquals(DestinationStatus::PENDING, $this->destination->status);
        $this->assertEquals('busy', $this->destination->last_disposition);
        $this->assertNotNull($this->destination->next_retry_at);

        // Verify retry is scheduled in the future
        $this->assertTrue(
            $this->destination->next_retry_at->greaterThan(now()),
            'Next retry should be in the future'
        );
    }

    /**
     * Test no-answer disposition schedules retry.
     */
    public function test_no_answer_disposition_schedules_retry(): void
    {
        $payload = [
            'type' => 'call.no-answer',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200);

        $this->destination = $this->destination->fresh();

        $this->assertEquals(DestinationStatus::PENDING, $this->destination->status);
        $this->assertEquals('no-answer', $this->destination->last_disposition);
        $this->assertNotNull($this->destination->next_retry_at);
    }

    /**
     * Test failed disposition after max attempts marks as failed.
     */
    public function test_failed_disposition_after_max_attempts_marks_failed(): void
    {
        // Set dial attempts to max
        $this->destination->update(['dial_attempts' => 3]);

        $payload = [
            'type' => 'call.failed',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'disposition' => 'busy',
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200);

        $this->destination = $this->destination->fresh();

        $this->assertEquals(DestinationStatus::FAILED, $this->destination->status);
        $this->assertEquals('busy', $this->destination->last_disposition);
    }

    /**
     * Test AMD completed webhook is ignored by the proxy endpoint.
     */
    public function test_amd_completed_webhook_is_ignored(): void
    {
        $payload = [
            'type' => 'amd.completed',
            'call_id' => 'test-call-id-456',
            'session_token' => 'test-session-token-123',
            'result' => 'human',
            'confidence' => 0.95,
            'custom_data' => [
                'campaign_id' => $this->campaign->id,
                'destination_id' => $this->destination->id,
            ],
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored', 'reason' => 'unknown_event_type']);

        $this->session = $this->session->fresh();

        $this->assertNull($this->session->amd_result);
        $this->assertNull($this->session->amd_confidence);
    }

    /**
     * Test session not found returns 404.
     */
    public function test_session_not_found_returns_404(): void
    {
        $payload = [
            'type' => 'call.completed',
            'call_id' => 'non-existent-call-id',
            'session_token' => 'non-existent-token',
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(404)
            ->assertJson(['status' => 'error', 'message' => 'Session not found']);
    }

    /**
     * Test unknown event type returns ignored status.
     */
    public function test_unknown_event_type_returns_ignored(): void
    {
        $payload = [
            'type' => 'unknown.event',
            'call_id' => 'test-call-id-456',
            'domain' => $this->cloudonixSettings->domain_name,
        ];

        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            $payload,
            ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
        );

        $response->assertStatus(200)
            ->assertJson(['status' => 'ignored', 'reason' => 'unknown_event_type']);
    }

    /**
     * Test exponential backoff calculation.
     */
    public function test_exponential_backoff_calculation(): void
    {
        // Update campaign to allow more retry attempts
        $this->campaign->update(['max_dial_attempts' => 10]);

        // Create destinations with different attempt counts
        $attempts = [1, 2, 3, 4, 5];
        $expectedDelays = [5, 10, 20, 40, 60]; // minutes

        foreach ($attempts as $index => $attemptCount) {
            $destination = AutoDialerDestination::factory()->create([
                'organization_id' => $this->organization->id,
                'list_id' => $this->list->id,
                'status' => DestinationStatus::DIALING,
                'dial_attempts' => $attemptCount,
            ]);

            $session = AutoDialerCallSession::factory()->create([
                'organization_id' => $this->organization->id,
                'campaign_id' => $this->campaign->id,
                'destination_id' => $destination->id,
                'status' => 'initiated',
                'session_token' => "test-token-{$attemptCount}",
            ]);

            $payload = [
                'type' => 'call.busy',
                'call_id' => "test-call-id-{$attemptCount}",
                'session_token' => "test-token-{$attemptCount}",
                'domain' => $this->cloudonixSettings->domain_name,
            ];

            $response = $this->postJson(
                '/api/webhooks/cloudonix/dialer',
                $payload,
                ['Authorization' => 'Bearer '.$this->cloudonixSettings->domain_requests_api_key]
            );

            $response->assertStatus(200);

            $destination = $destination->fresh();

            $this->assertNotNull($destination->next_retry_at);

            // Calculate expected time
            $expectedDelay = $expectedDelays[$index];
            $expectedTime = now()->addMinutes($expectedDelay);

            // Allow 1 minute tolerance for test execution time
            $this->assertTrue(
                $destination->next_retry_at->diffInMinutes($expectedTime) <= 1,
                "Attempt {$attemptCount} should have delay of {$expectedDelay} minutes"
            );
        }
    }
}
