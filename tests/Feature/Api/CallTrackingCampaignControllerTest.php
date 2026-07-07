<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Tracking Campaign API endpoints test suite.
 */
class CallTrackingCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $admin;

    private User $agent;

    private User $reporter;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        $this->reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::REPORTER,
        ]);

        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    public function test_index_returns_campaigns_for_organization(): void
    {
        Sanctum::actingAs($this->owner);

        CallTrackingCampaign::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        CallTrackingCampaign::factory()->count(2)->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson('/api/v1/call-tracking-campaigns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'status',
                        'destination_type',
                        'tracking_numbers_count',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        Sanctum::actingAs($this->owner);

        CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CallTrackingCampaignStatus::INACTIVE,
        ]);

        $response = $this->getJson('/api/v1/call-tracking-campaigns?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_index_searches_by_name(): void
    {
        Sanctum::actingAs($this->owner);

        CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Acme PPC Campaign',
        ]);

        CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Campaign',
        ]);

        $response = $this->getJson('/api/v1/call-tracking-campaigns?search=Acme');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Acme PPC Campaign');
    }

    public function test_owner_can_create_campaign(): void
    {
        Sanctum::actingAs($this->owner);

        $payload = [
            'name' => 'Spring PPC Campaign',
            'source' => 'Google Ads',
            'medium' => 'ppc',
            'destination_type' => 'forward',
            'destination_config' => ['forward_to' => '+15551234567'],
            'conversion_rule' => [
                'min_answered_duration_seconds' => 60,
                'requires_answered_disposition' => true,
            ],
        ];

        $response = $this->postJson('/api/v1/call-tracking-campaigns', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Spring PPC Campaign')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.destination_type', 'forward')
            ->assertJsonPath('data.destination_config.forward_to', '+15551234567');

        $this->assertDatabaseHas('call_tracking_campaigns', [
            'organization_id' => $this->organization->id,
            'name' => 'Spring PPC Campaign',
        ]);
    }

    public function test_admin_can_create_campaign(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'name' => 'Admin Created Campaign',
            'destination_type' => 'forward',
            'destination_config' => ['forward_to' => '+15551234567'],
        ];

        $response = $this->postJson('/api/v1/call-tracking-campaigns', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Admin Created Campaign');
    }

    public function test_agent_cannot_create_campaign(): void
    {
        Sanctum::actingAs($this->agent);

        $payload = [
            'name' => 'Unauthorized Campaign',
            'destination_type' => 'forward',
            'destination_config' => ['forward_to' => '+15551234567'],
        ];

        $response = $this->postJson('/api/v1/call-tracking-campaigns', $payload);

        $response->assertStatus(403);
    }

    public function test_create_validates_destination_config(): void
    {
        Sanctum::actingAs($this->owner);

        $payload = [
            'name' => 'Bad Campaign',
            'destination_type' => 'forward',
            'destination_config' => [],
        ];

        $response = $this->postJson('/api/v1/call-tracking-campaigns', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_config.forward_to']);
    }

    public function test_show_returns_campaign_with_tracking_numbers_count(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        CallTrackingNumber::factory()->count(2)->forCampaign($campaign)->create();

        $response = $this->getJson('/api/v1/call-tracking-campaigns/'.$campaign->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.tracking_numbers_count', 2);
    }

    public function test_show_enforces_tenant_isolation(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson('/api/v1/call-tracking-campaigns/'.$campaign->id);

        $response->assertStatus(404);
    }

    public function test_owner_can_update_campaign(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => ['forward_to' => '+15550000000'],
        ]);

        $payload = [
            'name' => 'Updated Campaign Name',
            'destination_config' => ['forward_to' => '+15551111111'],
        ];

        $response = $this->putJson('/api/v1/call-tracking-campaigns/'.$campaign->id, $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Campaign Name')
            ->assertJsonPath('data.destination_config.forward_to', '+15551111111');
    }

    public function test_agent_cannot_update_campaign(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson('/api/v1/call-tracking-campaigns/'.$campaign->id, [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_delete_campaign(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson('/api/v1/call-tracking-campaigns/'.$campaign->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('call_tracking_campaigns', ['id' => $campaign->id]);
    }

    public function test_agent_cannot_delete_campaign(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson('/api/v1/call-tracking-campaigns/'.$campaign->id);

        $response->assertStatus(403);
    }

    public function test_reporter_can_view_but_not_modify(): void
    {
        Sanctum::actingAs($this->reporter);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->getJson('/api/v1/call-tracking-campaigns')->assertStatus(200);
        $this->getJson('/api/v1/call-tracking-campaigns/'.$campaign->id)->assertStatus(200);
        $this->postJson('/api/v1/call-tracking-campaigns', [])->assertStatus(403);
        $this->putJson('/api/v1/call-tracking-campaigns/'.$campaign->id, [])->assertStatus(403);
        $this->deleteJson('/api/v1/call-tracking-campaigns/'.$campaign->id)->assertStatus(403);
    }

    public function test_create_accepts_ad_platform_upload_toggles(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/call-tracking-campaigns', [
            'name' => 'Toggled Campaign',
            'status' => 'active',
            'destination_type' => 'forward',
            'destination_config' => ['forward_to' => '+14155551234'],
            'google_ads_upload_enabled' => true,
            'meta_upload_enabled' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.google_ads_upload_enabled', true)
            ->assertJsonPath('data.meta_upload_enabled', true);
    }

    public function test_update_accepts_ad_platform_upload_toggles(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id,
            [
                'google_ads_upload_enabled' => true,
                'meta_upload_enabled' => false,
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.google_ads_upload_enabled', true)
            ->assertJsonPath('data.meta_upload_enabled', false);
    }
}
