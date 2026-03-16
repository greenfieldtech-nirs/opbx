<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\AutoDialer\CampaignStatus;
use App\Enums\AutoDialer\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoDialerCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    // ==================== INDEX ====================

    public function test_index_returns_campaigns_list(): void
    {
        AutoDialerCampaign::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_returns_empty_when_no_campaigns(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns?status='.CampaignStatus::RUNNING->value);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/auto-dialer/campaigns');

        $response->assertUnauthorized();
    }

    public function test_index_scopes_by_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== STORE ====================

    public function test_store_creates_new_campaign(): void
    {
        $data = [
            'name' => 'Test Campaign',
            'description' => 'A test campaign',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+1234567890',
            'max_concurrent_calls' => 5,
            'daily_start_time' => '09:00',
            'daily_end_time' => '17:00',
            'timezone' => 'America/New_York',
            'max_retry_attempts' => 3,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Campaign');

        $this->assertDatabaseHas('auto_dialer_campaigns', [
            'name' => 'Test Campaign',
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'routing_destination_type', 'routing_destination_id']);
    }

    public function test_store_validates_name_max_length(): void
    {
        $data = [
            'name' => str_repeat('a', 256),
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_max_concurrent_calls_range(): void
    {
        $data = [
            'name' => 'Test',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'max_concurrent_calls' => 0,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['max_concurrent_calls']);
    }

    public function test_store_validates_caller_id_format(): void
    {
        $data = [
            'name' => 'Test',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => 'invalid-phone',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['caller_id']);
    }

    public function test_store_allows_valid_e164_caller_id(): void
    {
        $data = [
            'name' => 'Test Campaign',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
            'caller_id' => '+14155551234',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertCreated();
    }

    public function test_store_defaults_max_concurrent_calls_to_10(): void
    {
        $data = [
            'name' => 'Test Campaign',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT->value,
            'routing_destination_id' => 1,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/auto-dialer/campaigns', $data);

        $response->assertCreated();

        $campaign = AutoDialerCampaign::first();
        $this->assertEquals(10, $campaign->max_concurrent_calls);
    }

    // ==================== SHOW ====================

    public function test_show_returns_campaign_details(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'name' => 'Test Campaign',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.name', 'Test Campaign');
    }

    public function test_show_returns_404_for_nonexistent_campaign(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns/99999');

        $response->assertNotFound();
    }

    public function test_show_returns_403_for_other_organization_campaign(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertForbidden();
    }

    public function test_show_includes_destinations_count(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        AutoDialerDestination::factory()->count(5)->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertOk()
            ->assertJsonPath('data.total_destinations', 5);
    }

    // ==================== UPDATE ====================

    public function test_update_modifies_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/auto-dialer/campaigns/'.$campaign->id, [
                'name' => 'New Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $campaign->refresh();
        $this->assertEquals('New Name', $campaign->name);
    }

    public function test_update_returns_403_for_other_organization_campaign(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/auto-dialer/campaigns/'.$campaign->id, [
                'name' => 'New Name',
            ]);

        $response->assertForbidden();
    }

    public function test_update_prevents_modifying_status_directly(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/auto-dialer/campaigns/'.$campaign->id, [
                'name' => 'Updated',
                'status' => CampaignStatus::RUNNING->value,
            ]);

        $response->assertOk();

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::PENDING->value, $campaign->status);
    }

    public function test_update_validates_fields(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/auto-dialer/campaigns/'.$campaign->id, [
                'max_concurrent_calls' => -1,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['max_concurrent_calls']);
    }

    // ==================== DESTROY ====================

    public function test_destroy_deletes_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('auto_dialer_campaigns', [
            'id' => $campaign->id,
        ]);
    }

    public function test_destroy_returns_403_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::RUNNING->value,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertForbidden();
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by_user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertForbidden();
    }

    public function test_destroy_cascades_to_destinations(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $this->user->id,
            'status' => CampaignStatus::PENDING->value,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'campaign_id' => $campaign->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/auto-dialer/campaigns/'.$campaign->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('auto_dialer_destinations', [
            'id' => $destination->id,
        ]);
    }
}
