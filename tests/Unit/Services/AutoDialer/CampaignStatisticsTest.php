<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutoDialer\CampaignStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $this->actingAs($this->user);
    }

    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        return AutoDialerCampaign::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
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

    public function test_get_stats_returns_correct_counts(): void
    {
        $campaign = $this->createCampaign();
        $this->createListWithDestinations($campaign, 5, ['status' => DestinationStatus::COMPLETED]);
        $this->createListWithDestinations($campaign, 3, ['status' => DestinationStatus::PENDING]);
        $this->createListWithDestinations($campaign, 2, ['status' => DestinationStatus::FAILED]);
        $this->createListWithDestinations($campaign, 1, ['status' => DestinationStatus::INVALID]);

        $stats = $this->statistics->getStats($campaign);

        $this->assertEquals(11, $stats['total']);
        $this->assertEquals(5, $stats['completed']);
        $this->assertEquals(3, $stats['pending']);
        $this->assertEquals(2, $stats['failed']);
        $this->assertEquals(1, $stats['invalid']);
        $this->assertEquals(73, $stats['progress_percentage']);
    }

    public function test_get_stats_calculates_progress_percentage(): void
    {
        $campaign = $this->createCampaign();
        $this->createListWithDestinations($campaign, 8, ['status' => DestinationStatus::COMPLETED]);
        $this->createListWithDestinations($campaign, 2, ['status' => DestinationStatus::FAILED]);

        $stats = $this->statistics->getStats($campaign);

        $this->assertEquals(10, $stats['total']);
        $this->assertEquals(100, $stats['progress_percentage']);
    }

    public function test_get_stats_returns_zero_for_empty_campaign(): void
    {
        $campaign = $this->createCampaign();

        $stats = $this->statistics->getStats($campaign);

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['completed']);
        $this->assertEquals(0, $stats['failed']);
        $this->assertEquals(0, $stats['progress_percentage']);
    }

    public function test_get_stats_uses_cache_when_available(): void
    {
        $campaign = $this->createCampaign();
        $cacheKey = "auto_dialer:campaign_stats:{$campaign->id}";
        Cache::put($cacheKey, ['total' => 99, 'completed' => 99, 'failed' => 0, 'invalid' => 0, 'pending' => 0, 'progress_percentage' => 100], 300);

        $stats = $this->statistics->getStats($campaign);

        $this->assertEquals(99, $stats['total']);
    }

    public function test_update_counts_updates_campaign_fields(): void
    {
        $campaign = $this->createCampaign([
            'total_destinations' => 0,
            'completed_calls' => 0,
            'failed_calls' => 0,
            'pending_calls' => 0,
        ]);
        $this->createListWithDestinations($campaign, 5, ['status' => DestinationStatus::COMPLETED]);
        $this->createListWithDestinations($campaign, 3, ['status' => DestinationStatus::FAILED]);
        $this->createListWithDestinations($campaign, 2, ['status' => DestinationStatus::PENDING]);

        $this->statistics->updateCounts($campaign);

        $campaign->refresh();
        $this->assertEquals(10, $campaign->total_destinations);
        $this->assertEquals(5, $campaign->completed_calls);
        $this->assertEquals(3, $campaign->failed_calls);
        $this->assertEquals(2, $campaign->pending_calls);
    }

    public function test_update_counts_caches_stats(): void
    {
        $campaign = $this->createCampaign();
        $this->createListWithDestinations($campaign, 3, ['status' => DestinationStatus::PENDING]);

        $this->statistics->updateCounts($campaign);

        $cacheKey = "auto_dialer:campaign_stats:{$campaign->id}";
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_clear_cache_removes_cached_stats(): void
    {
        $campaign = $this->createCampaign();
        $cacheKey = "auto_dialer:campaign_stats:{$campaign->id}";
        Cache::put($cacheKey, ['total' => 1], 300);

        $this->statistics->clearCache($campaign);

        $this->assertFalse(Cache::has($cacheKey));
    }
}
