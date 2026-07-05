<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Jobs\DialDestinationJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
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
        $this->processor = app(CampaignProcessor::class);
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
            'start_date' => '2020-01-01',
            'end_date' => '2030-12-31',
            'days_active' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ], $overrides));
    }

    private function createListWithDestinations(AutoDialerCampaign $campaign, int $count, array $overrides = []): AutoDialerList
    {
        $list = AutoDialerList::factory()->assignedToCampaign($campaign)->create();

        AutoDialerDestination::factory()->count($count)->create(array_merge([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
        ], $overrides));

        return $list;
    }

    public function test_process_queues_dial_jobs_for_pending_destinations(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        Queue::assertPushed(DialDestinationJob::class, 3);
    }

    public function test_process_only_processes_pending_destinations(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
        ]);

        $list = $this->createListWithDestinations($campaign, 0);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING,
        ]);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED,
        ]);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED,
        ]);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::INVALID,
        ]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        // Only pending (failed can retry but is also counted as pending for dialing)
        Queue::assertPushed(DialDestinationJob::class, 2);
    }

    public function test_process_does_not_queue_jobs_when_campaign_paused(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::PAUSED,
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->processor->process($campaign);

        Queue::assertNothingPushed();
    }

    public function test_process_does_not_queue_jobs_when_campaign_completed(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::COMPLETED,
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->processor->process($campaign);

        Queue::assertNothingPushed();
    }

    public function test_process_does_not_queue_jobs_outside_scheduled_hours(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->travelTo(now()->setTime(23, 0));

        $this->processor->process($campaign);

        Queue::assertNothingPushed();
    }

    public function test_process_queues_jobs_during_scheduled_hours(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        Queue::assertPushed(DialDestinationJob::class, 3);
    }

    public function test_process_handles_timezone_correctly(): void
    {
        Queue::fake();

        // Freeze to a deterministic weekday before creating the campaign so the
        // factory's start_date/end_date align with the time we assert against.
        $this->travelTo(now('UTC')->setDate(2026, 7, 3)->setTime(14, 0));

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'timezone' => 'America/New_York',
            'started_at' => now(),
        ]);

        $this->createListWithDestinations($campaign, 3, [
            'status' => DestinationStatus::PENDING,
        ]);

        $this->travelTo(now('America/New_York')->setTime(10, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        Queue::assertPushed(DialDestinationJob::class, 3);
    }

    public function test_process_returns_early_when_no_pending_destinations(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
        ]);

        $this->createListWithDestinations($campaign, 0);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        Queue::assertNothingPushed();
    }

    public function test_process_completes_campaign_when_all_destinations_finished(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'start_time' => 9,
            'end_time' => 17,
            'started_at' => now(),
            'total_destinations' => 2,
        ]);

        $list = $this->createListWithDestinations($campaign, 0);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED,
        ]);

        AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED,
        ]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();
        $this->processor->process($campaign);

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::COMPLETED, $campaign->status);
        $this->assertNotNull($campaign->completed_at);
    }
}
