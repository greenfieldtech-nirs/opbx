<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AutoDialer\CampaignStatus;
use App\Enums\AutoDialer\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignLifecycleManager;
use App\Services\AutoDialer\CampaignStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignLifecycleManagerTest extends TestCase
{
    use RefreshDatabase;

    private CampaignLifecycleManager $manager;

    private CampaignStatistics $statistics;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statistics = new CampaignStatistics;
        $this->manager = new CampaignLifecycleManager($this->statistics);
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_start_transitions_campaign_to_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $result = $this->manager->start($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::RUNNING->value, $campaign->status);
        $this->assertNotNull($campaign->started_at);
        $this->assertNull($campaign->ended_at);
    }

    public function test_start_returns_false_when_already_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'started_at' => now(),
        ]);

        $result = $this->manager->start($campaign);

        $this->assertFalse($result);
    }

    public function test_start_returns_false_when_already_completed(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
            'started_at' => now()->subDay(),
            'ended_at' => now(),
        ]);

        $result = $this->manager->start($campaign);

        $this->assertFalse($result);
    }

    public function test_pause_transitions_campaign_to_paused(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $result = $this->manager->pause($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::PAUSED->value, $campaign->status);
    }

    public function test_pause_returns_false_when_not_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $result = $this->manager->pause($campaign);

        $this->assertFalse($result);
    }

    public function test_resume_transitions_campaign_to_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
            'started_at' => now()->subHour(),
        ]);

        $result = $this->manager->resume($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::RUNNING->value, $campaign->status);
    }

    public function test_resume_returns_false_when_not_paused(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $result = $this->manager->resume($campaign);

        $this->assertFalse($result);
    }

    public function test_stop_transitions_campaign_to_completed(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'started_at' => now()->subHour(),
        ]);

        $result = $this->manager->stop($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::COMPLETED->value, $campaign->status);
        $this->assertNotNull($campaign->ended_at);
    }

    public function test_stop_cancels_pending_destinations(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $pendingDestination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $inProgressDestination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $this->manager->stop($campaign);

        $pendingDestination->refresh();
        $inProgressDestination->refresh();

        $this->assertEquals(DestinationStatus::SKIPPED->value, $pendingDestination->status);
        $this->assertEquals(DestinationStatus::IN_PROGRESS->value, $inProgressDestination->status);
    }

    public function test_stop_updates_campaign_statistics(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'total_destinations' => 0,
            'completed_calls' => 0,
            'failed_calls' => 0,
        ]);

        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        $this->manager->stop($campaign);

        $campaign->refresh();

        $this->assertEquals(7, $campaign->total_destinations);
        $this->assertEquals(5, $campaign->completed_calls);
        $this->assertEquals(2, $campaign->failed_calls);
    }

    public function test_check_completion_completes_campaign_when_all_destinations_finished(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        $result = $this->manager->checkCompletion($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::COMPLETED->value, $campaign->status);
        $this->assertNotNull($campaign->ended_at);
    }

    public function test_check_completion_returns_false_when_pending_destinations_remain(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $result = $this->manager->checkCompletion($campaign);

        $this->assertFalse($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::RUNNING->value, $campaign->status);
    }

    public function test_check_completion_returns_false_when_not_running(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
        ]);

        $result = $this->manager->checkCompletion($campaign);

        $this->assertFalse($result);
    }

    public function test_cancel_pending_campaign_sets_status_to_cancelled(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $result = $this->manager->cancel($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::CANCELLED->value, $campaign->status);
    }

    public function test_cancel_running_campaign_stops_and_cancels(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
            'started_at' => now()->subHour(),
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $result = $this->manager->cancel($campaign);

        $this->assertTrue($result);
        $campaign->refresh();
        $this->assertEquals(CampaignStatus::CANCELLED->value, $campaign->status);
        $this->assertNotNull($campaign->ended_at);
    }

    public function test_cancel_returns_false_when_already_completed(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
        ]);

        $result = $this->manager->cancel($campaign);

        $this->assertFalse($result);
    }

    public function test_cancel_returns_false_when_already_cancelled(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::CANCELLED->value,
        ]);

        $result = $this->manager->cancel($campaign);

        $this->assertFalse($result);
    }

    public function test_can_start_returns_true_for_pending_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $this->assertTrue($this->manager->canStart($campaign));
    }

    public function test_can_start_returns_false_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $this->assertFalse($this->manager->canStart($campaign));
    }

    public function test_can_start_returns_false_for_completed_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
        ]);

        $this->assertFalse($this->manager->canStart($campaign));
    }

    public function test_can_pause_returns_true_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $this->assertTrue($this->manager->canPause($campaign));
    }

    public function test_can_pause_returns_false_for_pending_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $this->assertFalse($this->manager->canPause($campaign));
    }

    public function test_can_resume_returns_true_for_paused_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
        ]);

        $this->assertTrue($this->manager->canResume($campaign));
    }

    public function test_can_resume_returns_false_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $this->assertFalse($this->manager->canResume($campaign));
    }

    public function test_can_stop_returns_true_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $this->assertTrue($this->manager->canStop($campaign));
    }

    public function test_can_stop_returns_true_for_paused_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PAUSED->value,
        ]);

        $this->assertTrue($this->manager->canStop($campaign));
    }

    public function test_can_stop_returns_false_for_pending_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $this->assertFalse($this->manager->canStop($campaign));
    }

    public function test_can_stop_returns_false_for_completed_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
        ]);

        $this->assertFalse($this->manager->canStop($campaign));
    }

    public function test_can_cancel_returns_true_for_pending_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $this->assertTrue($this->manager->canCancel($campaign));
    }

    public function test_can_cancel_returns_true_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $this->assertTrue($this->manager->canCancel($campaign));
    }

    public function test_can_cancel_returns_false_for_completed_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::COMPLETED->value,
        ]);

        $this->assertFalse($this->manager->canCancel($campaign));
    }

    public function test_can_cancel_returns_false_for_cancelled_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::CANCELLED->value,
        ]);

        $this->assertFalse($this->manager->canCancel($campaign));
    }
}
