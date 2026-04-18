<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auto Dialer Monitor Feature Tests
 *
 * Tests the real-time monitoring endpoints for auto dialer campaigns.
 */
class AutoDialerMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $adminUser;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        // Admin user can manage campaigns (owner/pbx_admin role)
        $this->adminUser = User::factory()->admin()->create([
            'organization_id' => $this->organization->id,
        ]);

        // Regular user cannot manage campaigns (pbx_user role)
        $this->regularUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        // Mock HTTP calls to dialer worker to avoid connection errors
        Http::preventStrayRequests();
    }

    // ═══════════════════════════════════════════════════════════
    // MONITOR SUMMARY ENDPOINT TESTS
    // GET /api/v1/auto-dialer-campaigns/monitor/summary
    // ═══════════════════════════════════════════════════════════

    public function test_monitor_summary_returns_200_with_correct_structure(): void
    {
        // Create active and paused campaigns
        $activeCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'name' => 'Active Campaign',
            'total_destinations' => 100,
            'completed_calls' => 50,
            'failed_calls' => 10,
            'pending_calls' => 40,
            'concurrent_active_calls' => 10,
        ]);

        $pausedCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
            'name' => 'Paused Campaign',
            'total_destinations' => 200,
            'completed_calls' => 100,
            'failed_calls' => 20,
            'pending_calls' => 80,
            'concurrent_active_calls' => 20,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'campaigns' => [
                        '*' => [
                            'id',
                            'name',
                            'status',
                            'progress_percentage',
                            'total_destinations',
                            'completed_calls',
                            'failed_calls',
                            'pending_calls',
                            'concurrent_active_calls',
                            'active_calls',
                            'cac_utilization',
                            'rate_limit_status' => [
                                'is_rate_limited',
                                'pause_reason',
                                'resumes_at',
                            ],
                            'caller_id',
                            'routing_destination_type',
                            'routing_destination_label',
                            'start_date',
                            'end_date',
                        ],
                    ],
                    'totals' => [
                        'active_campaigns',
                        'paused_campaigns',
                        'total_active_calls',
                        'total_cac_capacity',
                        'overall_utilization',
                    ],
                    'worker_health' => [
                        'status',
                        'active_campaigns',
                        'active_calls',
                        'queue_depth',
                    ],
                ],
            ]);

        // Verify campaigns are included
        $campaignIds = collect($response->json('data.campaigns'))->pluck('id')->toArray();
        $this->assertContains($activeCampaign->id, $campaignIds);
        $this->assertContains($pausedCampaign->id, $campaignIds);
    }

    public function test_monitor_summary_only_includes_active_and_paused_campaigns(): void
    {
        // Create campaigns in various statuses
        $activeCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'name' => 'Active Campaign',
        ]);

        $pausedCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
            'name' => 'Paused Campaign',
        ]);

        $draftCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT,
            'name' => 'Draft Campaign',
        ]);

        $completedCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::COMPLETED,
            'name' => 'Completed Campaign',
        ]);

        $archivedCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ARCHIVED,
            'name' => 'Archived Campaign',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk();

        $campaignIds = collect($response->json('data.campaigns'))->pluck('id')->toArray();

        // Active and paused should be included
        $this->assertContains($activeCampaign->id, $campaignIds);
        $this->assertContains($pausedCampaign->id, $campaignIds);

        // Draft, completed, and archived should NOT be included
        $this->assertNotContains($draftCampaign->id, $campaignIds);
        $this->assertNotContains($completedCampaign->id, $campaignIds);
        $this->assertNotContains($archivedCampaign->id, $campaignIds);
    }

    public function test_monitor_summary_is_tenant_scoped(): void
    {
        $otherOrganization = Organization::factory()->create();

        // Create campaign in user's organization
        $ownCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'name' => 'Own Campaign',
        ]);

        // Create campaign in other organization
        $otherCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'status' => CampaignStatus::ACTIVE,
            'name' => 'Other Campaign',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk();

        $campaignIds = collect($response->json('data.campaigns'))->pluck('id')->toArray();

        // Own campaign should be included
        $this->assertContains($ownCampaign->id, $campaignIds);

        // Other organization's campaign should NOT be included
        $this->assertNotContains($otherCampaign->id, $campaignIds);
    }

    public function test_monitor_summary_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertUnauthorized();
    }

    public function test_monitor_summary_requires_authorization(): void
    {
        // Regular user (pbx_user) cannot view campaigns
        $response = $this->actingAs($this->regularUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertForbidden();
    }

    public function test_monitor_summary_returns_correct_totals_aggregation(): void
    {
        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'concurrent_active_calls' => 10,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'concurrent_active_calls' => 20,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
            'concurrent_active_calls' => 15,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk();

        $totals = $response->json('data.totals');

        $this->assertEquals(2, $totals['active_campaigns']);
        $this->assertEquals(1, $totals['paused_campaigns']);
        $this->assertEquals(45, $totals['total_cac_capacity']);
        $this->assertEquals(0, $totals['total_active_calls']); // Redis returns null in tests
    }

    public function test_monitor_summary_returns_empty_campaigns_array_when_none_active(): void
    {
        // Create only draft campaigns
        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::COMPLETED,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk()
            ->assertJsonPath('data.campaigns', [])
            ->assertJsonPath('data.totals.active_campaigns', 0)
            ->assertJsonPath('data.totals.paused_campaigns', 0);
    }

    public function test_monitor_summary_owner_can_access(): void
    {
        $ownerUser = User::factory()->owner()->create([
            'organization_id' => $this->organization->id,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->actingAs($ownerUser)
            ->getJson('/api/v1/auto-dialer-campaigns/monitor/summary');

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════
    // MONITOR DETAIL ENDPOINT TESTS
    // GET /api/v1/auto-dialer-campaigns/{campaign}/monitor/detail
    // ═══════════════════════════════════════════════════════════

    public function test_monitor_detail_returns_200_with_correct_structure(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
            'name' => 'Test Campaign',
            'total_destinations' => 100,
            'completed_calls' => 50,
            'failed_calls' => 10,
            'pending_calls' => 40,
            'concurrent_active_calls' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'campaign' => [
                        'id',
                        'name',
                        'status',
                        'concurrent_active_calls',
                        'active_calls',
                        'cac_utilization',
                    ],
                    'statistics' => [
                        'total_destinations',
                        'completed_calls',
                        'failed_calls',
                        'pending_calls',
                        'progress_percentage',
                        'avg_duration_seconds',
                        'avg_billsec_seconds',
                    ],
                    'dispositions' => [
                        'answered',
                        'completed',
                        'busy',
                        'no_answer',
                        'failed',
                        'cancelled',
                        'congestion',
                    ],
                    'rate_limit_status' => [
                        'is_rate_limited',
                        'pause_reason',
                        'resumes_at',
                        'can_resume_now',
                    ],
                ],
            ]);
    }

    public function test_monitor_detail_returns_correct_disposition_breakdown(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
        ]);

        // Create call sessions with various dispositions
        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'completed',
            'disposition' => 'answered',
        ]);

        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'completed',
            'disposition' => 'answered',
        ]);

        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'failed',
            'disposition' => 'busy',
        ]);

        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'failed',
            'disposition' => 'no_answer',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk();

        $dispositions = $response->json('data.dispositions');

        $this->assertEquals(2, $dispositions['answered']);
        $this->assertEquals(1, $dispositions['busy']);
        $this->assertEquals(1, $dispositions['no_answer']);
        $this->assertEquals(0, $dispositions['completed']); // Different from answered
        $this->assertEquals(0, $dispositions['failed']); // Different disposition
        $this->assertEquals(0, $dispositions['cancelled']);
        $this->assertEquals(0, $dispositions['congestion']);
    }

    public function test_monitor_detail_returns_correct_average_duration_statistics(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'list_id' => $list->id,
        ]);

        // Create completed sessions with specific durations
        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'completed',
            'disposition' => 'answered',
            'duration' => 100,
            'billsec' => 90,
        ]);

        AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'status' => 'completed',
            'disposition' => 'answered',
            'duration' => 200,
            'billsec' => 180,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk();

        $statistics = $response->json('data.statistics');

        // Average duration: (100 + 200) / 2 = 150
        $this->assertEquals(150, $statistics['avg_duration_seconds']);

        // Average billsec: (90 + 180) / 2 = 135
        $this->assertEquals(135, $statistics['avg_billsec_seconds']);
    }

    public function test_monitor_detail_returns_zero_for_empty_statistics(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk();

        $statistics = $response->json('data.statistics');

        $this->assertEquals(0, $statistics['avg_duration_seconds']);
        $this->assertEquals(0, $statistics['avg_billsec_seconds']);

        $dispositions = $response->json('data.dispositions');
        foreach ($dispositions as $count) {
            $this->assertEquals(0, $count);
        }
    }

    public function test_monitor_detail_returns_403_for_different_organization(): void
    {
        $otherOrganization = Organization::factory()->create();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertForbidden();
    }

    public function test_monitor_detail_returns_404_for_non_existent_campaign(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/auto-dialer-campaigns/99999/monitor/detail');

        $response->assertNotFound();
    }

    public function test_monitor_detail_requires_authentication(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertUnauthorized();
    }

    public function test_monitor_detail_allows_same_organization_users(): void
    {
        // The view policy only checks organization, not role
        // So regular users in the same org can view campaign details
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->regularUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk();
    }

    public function test_monitor_detail_owner_can_access(): void
    {
        $ownerUser = User::factory()->owner()->create([
            'organization_id' => $this->organization->id,
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE,
        ]);

        $response = $this->actingAs($ownerUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk();
    }

    public function test_monitor_detail_works_with_paused_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
            'pause_reason' => 'Manual pause',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk()
            ->assertJsonPath('data.campaign.status', 'paused')
            ->assertJsonPath('data.rate_limit_status.is_rate_limited', false)
            ->assertJsonPath('data.rate_limit_status.pause_reason', 'Manual pause');
    }

    public function test_monitor_detail_shows_rate_limited_status(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::PAUSED,
            'pause_reason' => 'cloudonix_rate_limit',
            'resume_at' => now()->addMinutes(30),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/auto-dialer-campaigns/{$campaign->id}/monitor/detail");

        $response->assertOk()
            ->assertJsonPath('data.rate_limit_status.is_rate_limited', true)
            ->assertJsonPath('data.rate_limit_status.pause_reason', 'cloudonix_rate_limit')
            ->assertJsonPath('data.rate_limit_status.can_resume_now', false);
    }
}
