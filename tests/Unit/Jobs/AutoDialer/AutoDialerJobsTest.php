<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\RoutingDestinationType;
use App\Jobs\DialDestinationJob;
use App\Jobs\ProcessAutoDialerCampaignJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignProcessor;
use App\Services\AutoDialer\DestinationValidator;
use App\Services\AutoDialer\DialingScheduler;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoDialerJobsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->actingAs($this->user);
    }

    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
        ], $overrides));
    }

    private function createDestination(AutoDialerCampaign $campaign, array $overrides = []): AutoDialerDestination
    {
        $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();

        return AutoDialerDestination::factory()->create(array_merge([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
        ], $overrides));
    }

    // ==================== ProcessAutoDialerCampaignJob ====================

    public function test_process_campaign_job_processes_active_campaign(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);
        $this->createDestination($campaign, ['status' => DestinationStatus::PENDING]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();

        $processor = app(CampaignProcessor::class);
        $job = new ProcessAutoDialerCampaignJob($campaign->id);
        $job->handle($processor, app(DialingScheduler::class));

        // Campaign should still be active and scheduled next batch
        Queue::assertPushed(ProcessAutoDialerCampaignJob::class, 1);
    }

    public function test_process_campaign_job_reschedules_when_outside_schedule(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
        ]);
        $this->createDestination($campaign, ['status' => DestinationStatus::PENDING]);

        $this->travelTo(now()->setTime(23, 0));
        $campaign->refresh();

        $processor = app(CampaignProcessor::class);
        $job = new ProcessAutoDialerCampaignJob($campaign->id);
        $job->handle($processor, app(DialingScheduler::class));

        Queue::assertPushed(ProcessAutoDialerCampaignJob::class, 1);
    }

    public function test_process_campaign_job_skips_non_active_campaigns(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::PAUSED,
        ]);

        $processor = app(CampaignProcessor::class);
        $job = new ProcessAutoDialerCampaignJob($campaign->id);
        $job->handle($processor, app(DialingScheduler::class));

        Queue::assertNothingPushed();
    }

    public function test_process_campaign_job_handles_missing_campaign(): void
    {
        Queue::fake();

        $processor = app(CampaignProcessor::class);
        $job = new ProcessAutoDialerCampaignJob(99999);
        $job->handle($processor, app(DialingScheduler::class));

        Queue::assertNothingPushed();
    }

    // ==================== DialDestinationJob ====================

    public function test_dial_destination_job_dials_valid_destination(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
            'max_dial_attempts' => 3,
        ]);
        $destination = $this->createDestination($campaign, [
            'status' => DestinationStatus::PENDING,
            'phone_number' => '+14155551234',
        ]);

        $mockValidator = $this->createMock(DestinationValidator::class);
        $mockValidator->method('validate')->willReturn([
            'valid' => true,
            'error' => null,
            'trunk' => 'test-trunk',
        ]);
        $this->instance(DestinationValidator::class, $mockValidator);

        $mockClient = $this->createMock(CloudonixClient::class);
        $mockClient->method('initiateCall')->willReturn([
            'sessionToken' => 'test-session-123',
            'callId' => 'test-call-123',
        ]);
        $this->instance(CloudonixClient::class, $mockClient);

        $validator = app(DestinationValidator::class);
        $job = new DialDestinationJob($destination->id, $campaign->id);
        $job->handle($validator, $mockClient);

        $destination->refresh();
        $this->assertEquals(DestinationStatus::DIALING, $destination->status);
        $this->assertNotNull($destination->last_dialed_at);
        $this->assertEquals('test-call-123', $destination->last_call_id);
    }

    public function test_dial_destination_job_marks_invalid_as_invalid(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
        ]);
        $destination = $this->createDestination($campaign, [
            'status' => DestinationStatus::PENDING,
            'phone_number' => 'not-a-number',
        ]);

        $mockClient = $this->createMock(CloudonixClient::class);
        $this->instance(CloudonixClient::class, $mockClient);

        $validator = app(DestinationValidator::class);
        $job = new DialDestinationJob($destination->id, $campaign->id);
        $job->handle($validator, $mockClient);

        $destination->refresh();
        $this->assertEquals(DestinationStatus::INVALID, $destination->status);
    }

    public function test_dial_destination_job_handles_missing_destination(): void
    {
        $mockClient = $this->createMock(CloudonixClient::class);
        $validator = app(DestinationValidator::class);
        $job = new DialDestinationJob(99999, 99999);
        $job->handle($validator, $mockClient);

        $this->assertTrue(true);
    }
}
