<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Tracking Analytics API endpoints test suite.
 */
class CallTrackingAnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $otherOwner;

    private CallTrackingCampaign $campaign;

    private CallTrackingNumber $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Summer Campaign',
            'source' => 'google',
            'medium' => 'cpc',
        ]);

        $this->number = CallTrackingNumber::factory()->forCampaign($this->campaign)->create();
    }

    public function test_index_returns_kpis_and_time_series(): void
    {
        Sanctum::actingAs($this->owner);

        $this->createSession([
            'caller_number' => '+15551111111',
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $this->createSession([
            'caller_number' => '+15552222222',
            'duration' => 30,
            'is_answered' => true,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 11:00:00'),
        ]);

        $this->createSession([
            'caller_number' => '+15553333333',
            'duration' => 0,
            'is_answered' => false,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-16 12:00:00'),
        ]);

        $response = $this->getJson('/api/v1/call-tracking-analytics?start_date=2026-06-01&end_date=2026-06-30');

        $response->assertOk();

        $response->assertJsonPath('kpis.total_calls', 3);
        $response->assertJsonPath('kpis.unique_callers', 3);
        $response->assertJsonPath('kpis.answered_calls', 2);
        $response->assertJsonPath('kpis.missed_calls', 1);
        $response->assertJsonPath('kpis.conversions', 1);
        $response->assertJsonPath('filters.start_date', '2026-06-01');
        $response->assertJsonPath('filters.end_date', '2026-06-30');
        $response->assertJsonPath('filters.group_by', 'day');

        $timeSeries = $response->json('time_series');
        $this->assertCount(2, $timeSeries);
        $this->assertSame('2026-06-15', $timeSeries[0]['date_key']);
        $this->assertSame(2, $timeSeries[0]['calls']);
        $this->assertSame('2026-06-16', $timeSeries[1]['date_key']);
        $this->assertSame(1, $timeSeries[1]['calls']);

        $response->assertJsonPath('top_campaigns.0.campaign_name', 'Summer Campaign');
        $response->assertJsonPath('top_campaigns.0.calls', 3);
        $response->assertJsonPath('top_sources.0.source', 'google');
        $response->assertJsonPath('top_sources.0.calls', 3);
    }

    public function test_export_returns_csv_with_expected_headers_and_rows(): void
    {
        Sanctum::actingAs($this->owner);

        $this->createSession([
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $this->createSession([
            'duration' => 30,
            'is_answered' => true,
            'is_converted' => false,
            'started_at' => Carbon::parse('2026-06-15 11:00:00'),
        ]);

        $response = $this->get('/api/v1/call-tracking-analytics/export?start_date=2026-06-01&end_date=2026-06-30');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('call-tracking-analytics-', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $lines = array_filter(explode("\n", $csv));
        $headers = str_getcsv($lines[0]);

        $this->assertSame([
            'date',
            'campaign_name',
            'source',
            'medium',
            'calls',
            'answered',
            'missed',
            'conversions',
            'avg_duration',
        ], $headers);

        $this->assertCount(2, $lines);
        $row = str_getcsv($lines[1]);
        $this->assertSame('2026-06-15', $row[0]);
        $this->assertSame('Summer Campaign', $row[1]);
        $this->assertSame('google', $row[2]);
        $this->assertSame('cpc', $row[3]);
        $this->assertSame('2', $row[4]);
        $this->assertSame('2', $row[5]);
        $this->assertSame('0', $row[6]);
        $this->assertSame('1', $row[7]);
    }

    public function test_tenant_isolation_excludes_other_organization_data(): void
    {
        Sanctum::actingAs($this->otherOwner);

        $this->createSession([
            'duration' => 60,
            'is_answered' => true,
            'is_converted' => true,
            'started_at' => Carbon::parse('2026-06-15 10:00:00'),
        ]);

        $response = $this->getJson('/api/v1/call-tracking-analytics?start_date=2026-06-01&end_date=2026-06-30');

        $response->assertOk();
        $response->assertJsonPath('kpis.total_calls', 0);
        $response->assertJsonPath('kpis.conversions', 0);
        $response->assertJsonPath('time_series', []);
        $response->assertJsonPath('top_campaigns', []);
        $response->assertJsonPath('top_sources', []);
    }

    /**
     * Create a call tracking session bypassing the organization scope.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createSession(array $overrides = []): CallTrackingSession
    {
        return OrganizationScope::bypass(fn () => CallTrackingSession::factory()
            ->forCampaignAndNumber($this->campaign, $this->number)
            ->create($overrides));
    }
}
