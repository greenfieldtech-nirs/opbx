<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AutoDialer\CampaignStatus;
use App\Enums\AutoDialer\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignProcessorTest extends TestCase
{
    use RefreshDatabase;

    private CampaignProcessor $processor;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new CampaignProcessor;
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_process_calls_queues_dial_jobs_for_pending_destinations(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 5,
            'started_at' => now(),
        ]);

        $destinations = AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $this->processor->process($campaign);

        // Should queue 3 dial jobs (one for each destination)
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 3);
    }

    public function test_process_respects_max_concurrent_calls_limit(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 2,
            'started_at' => now(),
        ]);

        // Create 5 pending destinations
        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        // Create 1 active call (should reduce available slots)
        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $this->processor->process($campaign);

        // Should queue only 1 job (max 2 concurrent, 1 active = 1 available)
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 1);
    }

    public function test_process_only_processes_pending_destinations(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 10,
            'started_at' => now(),
        ]);

        // Create destinations with different statuses
        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::SKIPPED->value,
        ]);

        $this->processor->process($campaign);

        // Should queue only 1 job (only pending destination)
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 1);
    }

    public function test_process_does_not_queue_jobs_when_campaign_paused(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
            'max_concurrent_calls' => 10,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $this->processor->process($campaign);

        // Should not queue any jobs when paused
        Queue::assertNothingPushed();
    }

    public function test_process_does_not_queue_jobs_when_campaign_completed(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
            'max_concurrent_calls' => 10,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $this->processor->process($campaign);

        // Should not queue any jobs when completed
        Queue::assertNothingPushed();
    }

    public function test_process_does_not_queue_jobs_outside_scheduled_hours(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 10,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'started_at' => now(),
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        // Test outside business hours (e.g., 11 PM)
        $this->travelTo(now()->setTime(23, 0));

        $this->processor->process($campaign);

        // Should not queue any jobs outside scheduled hours
        Queue::assertNothingPushed();
    }

    public function test_process_queues_jobs_during_scheduled_hours(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 10,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'started_at' => now(),
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        // Test during business hours (e.g., 2 PM)
        $this->travelTo(now()->setTime(14, 0));

        $this->processor->process($campaign);

        // Should queue jobs during scheduled hours
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 3);
    }

    public function test_process_handles_timezone_correctly(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 10,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'America/New_York',
            'started_at' => now(),
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        // Test at 10 AM in campaign timezone (America/New_York)
        $this->travelTo(now()->timezone('America/New_York')->setTime(10, 0));

        $this->processor->process($campaign);

        // Should queue jobs during scheduled hours in correct timezone
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 3);
    }

    public function test_process_returns_early_when_no_pending_destinations(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 10,
            'started_at' => now(),
        ]);

        // No destinations created

        $this->processor->process($campaign);

        Queue::assertNothingPushed();
    }

    public function test_process_prioritizes_destinations_by_priority(): void
    {
        Queue::fake();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'max_concurrent_calls' => 2,
            'started_at' => now(),
        ]);

        // Create destinations with different priorities
        $lowPriority = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'priority' => 1,
            'phone_number' => '+1111111111',
        ]);

        $highPriority = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
            'priority' => 10,
            'phone_number' => '+2222222222',
        ]);

        $this->processor->process($campaign);

        // Should queue 2 jobs
        Queue::assertPushed(\App\Jobs\DialDestinationJob::class, 2);
    }
}
