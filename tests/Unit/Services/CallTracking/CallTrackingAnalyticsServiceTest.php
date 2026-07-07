<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\CallTrackingAnalyticsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CallTrackingAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CallTrackingAnalyticsService;
    }

    public function test_get_kpis_aggregates_basic_metrics(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'source' => 'google',
            'medium' => 'cpc',
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSession($campaign, $number, [
            'caller_number' => '+15551111111',
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSession($campaign, $number, [
            'caller_number' => '+15552222222',
            'duration' => 30,
            'is_answered' => true,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 11:00:00'),
        ]);
        $this->createSession($campaign, $number, [
            'caller_number' => '+15551111111',
            'duration' => 0,
            'is_answered' => false,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 12:00:00'),
        ]);

        $kpis = $this->service->getKpis([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
        ]);

        $this->assertSame(3, $kpis['total_calls']);
        $this->assertSame(2, $kpis['unique_callers']);
        $this->assertSame(2, $kpis['answered_calls']);
        $this->assertSame(1, $kpis['missed_calls']);
        $this->assertSame(30.0, $kpis['average_duration']);
        $this->assertSame(1, $kpis['conversions']);
        $this->assertEqualsWithDelta(33.33, $kpis['conversion_rate'], 0.01);
    }

    public function test_get_kpis_returns_zero_for_empty_range(): void
    {
        $organization = Organization::factory()->create();

        $kpis = $this->service->getKpis([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
        ]);

        $this->assertSame(0, $kpis['total_calls']);
        $this->assertSame(0, $kpis['unique_callers']);
        $this->assertSame(0, $kpis['answered_calls']);
        $this->assertSame(0, $kpis['missed_calls']);
        $this->assertSame(0.0, $kpis['average_duration']);
        $this->assertSame(0, $kpis['conversions']);
        $this->assertSame(0.0, $kpis['conversion_rate']);
    }

    public function test_get_kpis_filters_by_campaign(): void
    {
        $organization = Organization::factory()->create();
        $campaignA = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Campaign A',
        ]);
        $campaignB = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Campaign B',
        ]);
        $numberA = CallTrackingNumber::factory()->forCampaign($campaignA)->create();
        $numberB = CallTrackingNumber::factory()->forCampaign($campaignB)->create();

        $this->createSession($campaignA, $numberA, [
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSession($campaignB, $numberB, [
            'duration' => 30,
            'is_answered' => true,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 11:00:00'),
        ]);

        $kpis = $this->service->getKpis([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
            'campaign_ids' => [$campaignA->id],
        ]);

        $this->assertSame(1, $kpis['total_calls']);
        $this->assertSame(1, $kpis['conversions']);
        $this->assertEqualsWithDelta(100.0, $kpis['conversion_rate'], 0.01);
    }

    public function test_get_kpis_filters_by_source_and_medium(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'source' => 'google',
            'medium' => 'cpc',
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSession($campaign, $number, [
            'source' => 'google',
            'medium' => 'cpc',
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSession($campaign, $number, [
            'source' => 'facebook',
            'medium' => 'social',
            'duration' => 30,
            'is_answered' => true,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 11:00:00'),
        ]);

        $kpis = $this->service->getKpis([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
            'sources' => ['google'],
            'mediums' => ['cpc'],
        ]);

        $this->assertSame(1, $kpis['total_calls']);
        $this->assertSame(1, $kpis['conversions']);
    }

    public function test_get_time_series_groups_by_day(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSession($campaign, $number, [
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-14 10:00:00'),
        ]);
        $this->createSessions(2, $campaign, $number, [
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $series = $this->service->getTimeSeries([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
            'group_by' => 'day',
        ]);

        $this->assertCount(2, $series);
        $this->assertSame('2026-06-14', $series[0]['date_key']);
        $this->assertSame(1, $series[0]['calls']);
        $this->assertSame(1, $series[0]['conversions']);
        $this->assertSame('2026-06-15', $series[1]['date_key']);
        $this->assertSame(2, $series[1]['calls']);
        $this->assertSame(0, $series[1]['conversions']);
    }

    public function test_get_time_series_groups_by_week(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSession($campaign, $number, [
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-08 10:00:00'),
        ]);
        $this->createSessions(2, $campaign, $number, [
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $series = $this->service->getTimeSeries([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
            'group_by' => 'week',
        ]);

        $this->assertCount(2, $series);
        $this->assertSame('2026-24', $series[0]['date_key']);
        $this->assertSame(1, $series[0]['calls']);
        $this->assertSame(1, $series[0]['conversions']);
        $this->assertSame('2026-25', $series[1]['date_key']);
        $this->assertSame(2, $series[1]['calls']);
        $this->assertSame(0, $series[1]['conversions']);
    }

    public function test_get_time_series_groups_by_month(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSession($campaign, $number, [
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-05-15 10:00:00'),
        ]);
        $this->createSessions(2, $campaign, $number, [
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $series = $this->service->getTimeSeries([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-05-01'),
            'end_date' => Carbon::parse('2026-06-30'),
            'group_by' => 'month',
        ]);

        $this->assertCount(2, $series);
        $this->assertSame('2026-05', $series[0]['date_key']);
        $this->assertSame(1, $series[0]['calls']);
        $this->assertSame(1, $series[0]['conversions']);
        $this->assertSame('2026-06', $series[1]['date_key']);
        $this->assertSame(2, $series[1]['calls']);
        $this->assertSame(0, $series[1]['conversions']);
    }

    public function test_get_top_campaigns_orders_by_calls_and_respects_limit(): void
    {
        $organization = Organization::factory()->create();
        $campaignA = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Campaign A',
        ]);
        $campaignB = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Campaign B',
        ]);
        $campaignC = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Campaign C',
        ]);
        $numberA = CallTrackingNumber::factory()->forCampaign($campaignA)->create();
        $numberB = CallTrackingNumber::factory()->forCampaign($campaignB)->create();
        $numberC = CallTrackingNumber::factory()->forCampaign($campaignC)->create();

        $this->createSessions(3, $campaignA, $numberA, [
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSessions(2, $campaignB, $numberB, [
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSession($campaignC, $numberC, [
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $top = $this->service->getTopCampaigns([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
        ], 2);

        $this->assertCount(2, $top);
        $this->assertSame($campaignA->id, $top[0]['campaign_id']);
        $this->assertSame('Campaign A', $top[0]['campaign_name']);
        $this->assertSame(3, $top[0]['calls']);
        $this->assertSame(3, $top[0]['conversions']);
        $this->assertSame($campaignB->id, $top[1]['campaign_id']);
        $this->assertSame('Campaign B', $top[1]['campaign_name']);
        $this->assertSame(2, $top[1]['calls']);
        $this->assertSame(0, $top[1]['conversions']);
    }

    public function test_get_top_sources_orders_by_calls_and_respects_limit(): void
    {
        $organization = Organization::factory()->create();
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $this->createSessions(3, $campaign, $number, [
            'source' => 'google',
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSessions(2, $campaign, $number, [
            'source' => 'facebook',
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);
        $this->createSession($campaign, $number, [
            'source' => 'linkedin',
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $top = $this->service->getTopSources([
            'organization_id' => $organization->id,
            'start_date' => Carbon::parse('2026-06-01'),
            'end_date' => Carbon::parse('2026-06-30'),
        ], 2);

        $this->assertCount(2, $top);
        $this->assertSame('google', $top[0]['source']);
        $this->assertSame(3, $top[0]['calls']);
        $this->assertSame(3, $top[0]['conversions']);
        $this->assertSame('facebook', $top[1]['source']);
        $this->assertSame(2, $top[1]['calls']);
        $this->assertSame(0, $top[1]['conversions']);
    }

    private function createSession(CallTrackingCampaign $campaign, CallTrackingNumber $number, array $overrides = []): CallTrackingSession
    {
        return OrganizationScope::bypass(fn () => CallTrackingSession::factory()
            ->forCampaignAndNumber($campaign, $number)
            ->create($overrides));
    }

    /**
     * @return Collection<int, CallTrackingSession>
     */
    private function createSessions(int $count, CallTrackingCampaign $campaign, CallTrackingNumber $number, array $overrides = []): Collection
    {
        return OrganizationScope::bypass(fn () => CallTrackingSession::factory()
            ->forCampaignAndNumber($campaign, $number)
            ->count($count)
            ->create($overrides));
    }
}
