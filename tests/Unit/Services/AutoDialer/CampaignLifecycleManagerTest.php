<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\ListStatus;
use App\Jobs\ProcessAutoDialerCampaignJob;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignLifecycleManager;
use App\Services\AutoDialer\DialingScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignLifecycleManagerTest extends TestCase
{
    use RefreshDatabase;

    private CampaignLifecycleManager $manager;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new CampaignLifecycleManager(new DialingScheduler);
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

    public function test_start_transitions_draft_campaign_to_active(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
        ]);

        $result = $this->manager->start($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::ACTIVE, $campaign->status);
        $this->assertNotNull($campaign->started_at);
        Queue::assertPushed(ProcessAutoDialerCampaignJob::class, 1);
    }

    public function test_start_returns_false_when_already_active(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);

        $result = $this->manager->start($campaign);

        $this->assertFalse($result);
        Queue::assertNothingPushed();
    }

    public function test_start_returns_false_when_completed(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        $result = $this->manager->start($campaign);

        $this->assertFalse($result);
    }

    public function test_pause_transitions_active_campaign_to_paused(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $result = $this->manager->pause($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::PAUSED, $campaign->status);
    }

    public function test_pause_returns_false_when_not_active(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
        ]);

        $result = $this->manager->pause($campaign);

        $this->assertFalse($result);
    }

    public function test_resume_transitions_paused_campaign_to_active(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::PAUSED,
            'started_at' => now()->subHour(),
        ]);

        $result = $this->manager->resume($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::ACTIVE, $campaign->status);
        Queue::assertPushed(ProcessAutoDialerCampaignJob::class, 1);
    }

    public function test_resume_returns_false_when_not_paused(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $result = $this->manager->resume($campaign);

        $this->assertFalse($result);
    }

    public function test_complete_transitions_active_campaign_to_completed(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now()->subHour(),
        ]);

        $this->manager->complete($campaign);

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::COMPLETED, $campaign->status);
        $this->assertNotNull($campaign->completed_at);
    }

    public function test_archive_transitions_active_campaign_to_archived(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::ACTIVE,
        ]);

        $this->manager->archive($campaign);

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::ARCHIVED, $campaign->status);
    }

    public function test_archive_transitions_draft_campaign_to_archived(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
        ]);

        $this->manager->archive($campaign);

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::ARCHIVED, $campaign->status);
    }

    public function test_check_and_auto_start_starts_draft_campaign_within_schedule(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
            'auto_start' => true,
            'start_time' => 9,
            'end_time' => 17,
            'start_date' => now(),
            'end_date' => now()->addDays(7),
        ]);

        AutoDialerList::factory()->assignedToCampaign($campaign)->create([
            'status' => ListStatus::READY,
        ]);

        $this->travelTo(now()->setTime(14, 0));
        $campaign->refresh();

        $this->manager->checkAndAutoStart();

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::ACTIVE, $campaign->status);
        Queue::assertPushed(ProcessAutoDialerCampaignJob::class, 1);
    }

    public function test_check_and_auto_start_does_not_start_outside_schedule(): void
    {
        Queue::fake();

        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
            'auto_start' => true,
            'start_time' => 9,
            'end_time' => 17,
        ]);

        AutoDialerList::factory()->assignedToCampaign($campaign)->create([
            'status' => ListStatus::READY,
        ]);

        $this->travelTo(now()->setTime(23, 0));
        $campaign->refresh();

        $this->manager->checkAndAutoStart();

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::DRAFT, $campaign->status);
        Queue::assertNothingPushed();
    }

    public function test_get_status_summary_returns_expected_keys(): void
    {
        $campaign = $this->createCampaign([
            'status' => CampaignStatus::DRAFT,
        ]);

        $summary = $this->manager->getStatusSummary($campaign);

        $this->assertEquals($campaign->id, $summary['id']);
        $this->assertEquals('draft', $summary['status']);
        $this->assertEquals('Draft', $summary['status_label']);
        $this->assertTrue($summary['can_start']);
        $this->assertFalse($summary['can_pause']);
        $this->assertFalse($summary['is_runnable']);
    }
}
