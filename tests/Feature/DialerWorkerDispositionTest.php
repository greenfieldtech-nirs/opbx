<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests for DialerWorkerController disposition endpoint
 */
class DialerWorkerDispositionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private AutoDialerCampaign $campaign;

    private AutoDialerList $list;

    private AutoDialerDestination $destination;

    private AutoDialerCallSession $session;

    private string $workerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerToken = 'test-worker-token-123';
        Config::set('services.dialer_worker.token', $this->workerToken);

        $this->organization = Organization::factory()->create();

        $this->campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'max_dial_attempts' => 3,
            'pending_calls' => 1,
        ]);

        $this->list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
        ]);

        $this->destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $this->list->id,
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => 1,
        ]);

        $this->session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $this->campaign->id,
            'destination_id' => $this->destination->id,
            'status' => 'initiated',
            'worker_id' => 'test-worker-1',
        ]);
    }

    /**
     * Test disposition endpoint requires authentication.
     */
    public function test_disposition_endpoint_requires_authentication(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'should_retry' => false,
                'attempt_number' => 1,
            ]
        );

        $response->assertStatus(401);
    }

    /**
     * Test setting disposition to answered marks destination as completed.
     */
    public function test_answered_disposition_marks_completed(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'should_retry' => false,
                'attempt_number' => 1,
                'duration' => 45,
                'billsec' => 42,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Disposition set successfully',
                'data' => [
                    'session_id' => $this->session->id,
                    'disposition' => 'answered',
                    'destination_status' => 'completed',
                    'will_retry' => false,
                ],
            ]);

        $this->session = $this->session->fresh();
        $this->destination = $this->destination->fresh();

        $this->assertEquals('completed', $this->session->status);
        $this->assertEquals(DestinationStatus::COMPLETED, $this->destination->status);
        $this->assertEquals(45, $this->session->duration);
        $this->assertEquals(42, $this->session->billsec);
    }

    /**
     * Test setting disposition with retry schedules next retry.
     */
    public function test_retryable_disposition_with_should_retry_schedules_retry(): void
    {
        $nextRetry = now()->addMinutes(10)->toIso8601String();

        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'busy',
                'should_retry' => true,
                'next_retry_at' => $nextRetry,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Disposition set successfully',
                'data' => [
                    'session_id' => $this->session->id,
                    'disposition' => 'busy',
                    'destination_status' => 'pending',
                    'will_retry' => true,
                ],
            ]);

        $this->session = $this->session->fresh();
        $this->destination = $this->destination->fresh();

        $this->assertEquals('failed', $this->session->status);
        $this->assertEquals(DestinationStatus::PENDING, $this->destination->status);
        $this->assertEquals('busy', $this->destination->last_disposition);
    }

    /**
     * Test non-retryable disposition marks as failed even with should_retry=true.
     */
    public function test_non_retryable_disposition_marks_failed(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'failed',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);

        $this->session = $this->session->fresh();
        $this->destination = $this->destination->fresh();

        $this->assertEquals('failed', $this->session->status);
        $this->assertEquals(DestinationStatus::FAILED, $this->destination->status);
        $this->assertEquals('failed', $this->destination->last_disposition);
    }

    /**
     * Test disposition validation for invalid disposition values.
     */
    public function test_invalid_disposition_returns_validation_error(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'invalid-disposition',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['disposition']);
    }

    /**
     * Test disposition requires should_retry boolean.
     */
    public function test_disposition_requires_should_retry(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['should_retry']);
    }

    /**
     * Test disposition requires attempt_number.
     */
    public function test_disposition_requires_attempt_number(): void
    {
        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'should_retry' => false,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attempt_number']);
    }

    /**
     * Test disposition decrements campaign pending calls.
     */
    public function test_disposition_decrements_pending_calls(): void
    {
        $initialPending = $this->campaign->pending_calls;

        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);

        $this->campaign = $this->campaign->fresh();

        $this->assertEquals($initialPending - 1, $this->campaign->pending_calls);
    }

    /**
     * Test disposition increments completed_calls for answered.
     */
    public function test_answered_disposition_increments_completed_calls(): void
    {
        $initialCompleted = $this->campaign->completed_calls;

        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'answered',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);

        $this->campaign = $this->campaign->fresh();

        $this->assertEquals($initialCompleted + 1, $this->campaign->completed_calls);
    }

    /**
     * Test disposition increments failed_calls for non-retryable failures.
     */
    public function test_failed_disposition_increments_failed_calls(): void
    {
        $initialFailed = $this->campaign->failed_calls;

        $response = $this->postJson(
            "/api/v1/dialer/worker/calls/{$this->session->id}/disposition",
            [
                'disposition' => 'failed',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(200);

        $this->campaign = $this->campaign->fresh();

        $this->assertEquals($initialFailed + 1, $this->campaign->failed_calls);
    }

    /**
     * Test disposition for non-existent session returns 404.
     */
    public function test_disposition_for_nonexistent_session_returns_404(): void
    {
        $response = $this->postJson(
            '/api/v1/dialer/worker/calls/99999/disposition',
            [
                'disposition' => 'answered',
                'should_retry' => false,
                'attempt_number' => 1,
            ],
            ['Authorization' => 'Bearer '.$this->workerToken]
        );

        $response->assertStatus(404);
    }
}
