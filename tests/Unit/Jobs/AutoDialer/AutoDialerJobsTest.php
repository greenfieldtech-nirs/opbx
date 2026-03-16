<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\AutoDialer;

use App\Enums\AutoDialer\CampaignStatus;
use App\Enums\AutoDialer\DestinationStatus;
use App\Enums\AutoDialer\RoutingDestinationType;
use App\Jobs\DialDestinationJob;
use App\Jobs\ProcessAutoDialerCampaignJob;
use App\Jobs\UpdateDestinationStatusJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignProcessor;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    // ==================== ProcessAutoDialerCampaignJob ====================

    public function test_process_campaign_job_processes_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'started_at' => now(),
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $job = new ProcessAutoDialerCampaignJob($campaign->id);

        // Mock the processor to verify it's called
        $mockProcessor = $this->createMock(CampaignProcessor::class);
        $mockProcessor->expects($this->onc())
            ->method('process')
            ->with($this->callback(fn ($c) => $c->id === $campaign->id));

        $this->instance(CampaignProcessor::class, $mockProcessor);

        $job->handle();

        $this->assertTrue(true); // Job executed without exception
    }

    public function test_process_campaign_job_skips_non_running_campaigns(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
        ]);

        $job = new ProcessAutoDialerCampaignJob($campaign->id);

        // Should not throw exception, just return early
        $job->handle();

        $this->assertTrue(true); // No exception thrown
    }

    public function test_process_campaign_job_handles_missing_campaign(): void
    {
        $job = new ProcessAutoDialerCampaignJob(99999);

        // Should not throw exception, just return early
        $job->handle();

        $this->assertTrue(true); // No exception thrown
    }

    // ==================== DialDestinationJob ====================

    public function test_dial_destination_job_dials_valid_destination(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'phone_number' => '+14155551234',
            'valid' => true,
            'whitelist_status' => 'allowed',
        ]);

        // Mock CloudonixClient
        $mockClient = $this->createMock(CloudonixClient::class);
        $mockClient->expects($this->onc())
            ->method('initiateCall')
            ->willReturn([
                'call_id' => 'test-call-123',
                'status' => 'initiated',
            ]);

        $this->instance(CloudonixClient::class, $mockClient);

        $job = new DialDestinationJob($destination->id);
        $job->handle();

        $destination->refresh();
        $this->assertEquals(DestinationStatus::IN_PROGRESS->value, $destination->status);
        $this->assertNotNull($destination->last_dialed_at);
    }

    public function test_dial_destination_job_skips_invalid_destination(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'valid' => false,
        ]);

        $job = new DialDestinationJob($destination->id);
        $job->handle();

        $destination->refresh();
        // Destination should be marked as skipped
        $this->assertEquals(DestinationStatus::SKIPPED->value, $destination->status);
    }

    public function test_dial_destination_job_handles_api_failure(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'phone_number' => '+14155551234',
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 0,
        ]);

        // Mock CloudonixClient to return failure
        $mockClient = $this->createMock(CloudonixClient::class);
        $mockClient->expects($this->onc())
            ->method('initiateCall')
            ->willReturn(null);

        $this->instance(CloudonixClient::class, $mockClient);

        $job = new DialDestinationJob($destination->id);
        $job->handle();

        $destination->refresh();
        $this->assertEquals(DestinationStatus::PENDING->value, $destination->status);
        $this->assertEquals(1, $destination->retry_count);
    }

    public function test_dial_destination_job_increments_retry_count(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'phone_number' => '+14155551234',
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 1,
        ]);

        // Mock CloudonixClient to return failure
        $mockClient = $this->createMock(CloudonixClient::class);
        $mockClient->expects($this->onc())
            ->method('initiateCall')
            ->willReturn(null);

        $this->instance(CloudonixClient::class, $mockClient);

        $job = new DialDestinationJob($destination->id);
        $job->handle();

        $destination->refresh();
        $this->assertEquals(2, $destination->retry_count);
    }

    public function test_dial_destination_job_marks_failed_after_max_retries(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'phone_number' => '+14155551234',
            'valid' => true,
            'whitelist_status' => 'allowed',
            'retry_count' => 3, // At max retries
        ]);

        // Mock CloudonixClient to return failure
        $mockClient = $this->createMock(CloudonixClient::class);
        $mockClient->expects($this->onc())
            ->method('initiateCall')
            ->willReturn(null);

        $this->instance(CloudonixClient::class, $mockClient);

        $job = new DialDestinationJob($destination->id);
        $job->handle();

        $destination->refresh();
        $this->assertEquals(DestinationStatus::FAILED->value, $destination->status);
    }

    public function test_dial_destination_job_handles_missing_destination(): void
    {
        $job = new DialDestinationJob(99999);

        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }

    // ==================== UpdateDestinationStatusJob ====================

    public function test_update_status_job_updates_destination_status(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $job = new UpdateDestinationStatusJob(
            $destination->id,
            DestinationStatus::COMPLETED->value,
            [
                'call_duration' => 60,
                'call_id' => 'test-call-123',
            ]
        );
        $job->handle();

        $destination->refresh();
        $this->assertEquals(DestinationStatus::COMPLETED->value, $destination->status);
        $this->assertEquals(60, $destination->call_duration);
        $this->assertNotNull($destination->completed_at);
    }

    public function test_update_status_job_handles_amd_machine_detected(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'max_retry_attempts' => 3,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
            'retry_count' => 0,
        ]);

        $job = new UpdateDestinationStatusJob(
            $destination->id,
            DestinationStatus::AMD_MACHINE->value,
            ['amd_detected' => true]
        );
        $job->handle();

        $destination->refresh();
        // AMD machine detected should result in retry, so stays pending
        $this->assertEquals(DestinationStatus::PENDING->value, $destination->status);
        $this->assertEquals(1, $destination->retry_count);
    }

    public function test_update_status_job_handles_amd_human_detected(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $job = new UpdateDestinationStatusJob(
            $destination->id,
            DestinationStatus::AMD_HUMAN->value,
            ['amd_detected' => false]
        );
        $job->handle();

        $destination->refresh();
        // AMD human detected, continues to routing
        $this->assertEquals(DestinationStatus::IN_PROGRESS->value, $destination->status);
    }

    public function test_update_status_job_handles_missing_destination(): void
    {
        $job = new UpdateDestinationStatusJob(
            99999,
            DestinationStatus::COMPLETED->value,
            []
        );

        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }

    public function test_update_status_job_records_call_metadata(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $metadata = [
            'call_duration' => 120,
            'call_id' => 'call-12345',
            'hangup_cause' => 'NORMAL_CLEARING',
        ];

        $job = new UpdateDestinationStatusJob(
            $destination->id,
            DestinationStatus::COMPLETED->value,
            $metadata
        );
        $job->handle();

        $destination->refresh();
        $this->assertEquals(120, $destination->call_duration);
    }
}
