<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AutoDialer\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private CampaignStatistics $statistics;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statistics = new CampaignStatistics;
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_get_summary_returns_correct_totals(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // Create destinations with different statuses
        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        $summary = $this->statistics->getSummary($campaign);

        $this->assertEquals(11, $summary['total_destinations']);
        $this->assertEquals(5, $summary['completed']);
        $this->assertEquals(3, $summary['pending']);
        $this->assertEquals(2, $summary['in_progress']);
        $this->assertEquals(1, $summary['failed']);
    }

    public function test_get_summary_calculates_success_rate_correctly(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // 8 completed, 2 failed = 80% success rate
        AutoDialerDestination::factory()->count(8)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        $summary = $this->statistics->getSummary($campaign);

        $this->assertEquals(80.0, $summary['success_rate']);
    }

    public function test_get_summary_handles_zero_destinations(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $summary = $this->statistics->getSummary($campaign);

        $this->assertEquals(0, $summary['total_destinations']);
        $this->assertEquals(0, $summary['success_rate']);
    }

    public function test_get_summary_returns_zero_success_rate_when_no_completed_or_failed(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // Only pending destinations
        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $summary = $this->statistics->getSummary($campaign);

        $this->assertEquals(0, $summary['success_rate']);
    }

    public function test_get_detailed_stats_returns_correct_breakdown(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // Create destinations with different statuses
        AutoDialerDestination::factory()->count(10)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::SKIPPED->value,
        ]);

        $stats = $this->statistics->getDetailedStats($campaign);

        $this->assertEquals(10, $stats['by_status']['completed']);
        $this->assertEquals(5, $stats['by_status']['pending']);
        $this->assertEquals(3, $stats['by_status']['in_progress']);
        $this->assertEquals(2, $stats['by_status']['failed']);
        $this->assertEquals(1, $stats['by_status']['skipped']);
        $this->assertEquals(21, $stats['total']);
        $this->assertEqualsWithDelta(66.67, $stats['completion_percentage'], 0.01);
    }

    public function test_update_campaign_stats_updates_campaign_fields(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'total_destinations' => 0,
            'completed_calls' => 0,
            'failed_calls' => 0,
        ]);

        // Create destinations
        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        $this->statistics->updateCampaignStats($campaign);

        $campaign->refresh();

        $this->assertEquals(8, $campaign->total_destinations);
        $this->assertEquals(5, $campaign->completed_calls);
        $this->assertEquals(3, $campaign->failed_calls);
    }

    public function test_get_progress_percentage_calculates_correctly(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // 75% complete (6 completed, 2 failed, 2 pending)
        AutoDialerDestination::factory()->count(6)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::FAILED->value,
        ]);

        AutoDialerDestination::factory()->count(2)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $progress = $this->statistics->getProgressPercentage($campaign);

        $this->assertEquals(80.0, $progress); // (6 + 2) / 10 = 80%
    }

    public function test_get_progress_percentage_returns_zero_for_no_destinations(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $progress = $this->statistics->getProgressPercentage($campaign);

        $this->assertEquals(0.0, $progress);
    }

    public function test_is_complete_returns_true_when_all_finished(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        // All destinations completed or failed
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

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::SKIPPED->value,
        ]);

        $this->assertTrue($this->statistics->isComplete($campaign));
    }

    public function test_is_complete_returns_false_when_pending_remain(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::PENDING->value,
        ]);

        $this->assertFalse($this->statistics->isComplete($campaign));
    }

    public function test_is_complete_returns_false_when_in_progress(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        AutoDialerDestination::factory()->count(3)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::IN_PROGRESS->value,
        ]);

        $this->assertFalse($this->statistics->isComplete($campaign));
    }

    public function test_get_call_duration_stats_calculates_average(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
            'call_duration' => 60,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
            'call_duration' => 120,
        ]);

        AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
            'status' => DestinationStatus::COMPLETED->value,
            'call_duration' => 180,
        ]);

        $stats = $this->statistics->getCallDurationStats($campaign);

        $this->assertEquals(120, $stats['average_seconds']);
        $this->assertEquals(60, $stats['min_seconds']);
        $this->assertEquals(180, $stats['max_seconds']);
        $this->assertEquals(360, $stats['total_seconds']);
    }

    public function test_get_call_duration_stats_handles_no_completed_calls(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $stats = $this->statistics->getCallDurationStats($campaign);

        $this->assertEquals(0, $stats['average_seconds']);
        $this->assertEquals(0, $stats['min_seconds']);
        $this->assertEquals(0, $stats['max_seconds']);
        $this->assertEquals(0, $stats['total_seconds']);
    }
}
