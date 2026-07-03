<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\RoutingDestinationType;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
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
            'role' => UserRole::OWNER,
        ]);
    }

    /**
     * Return a valid schedule object for campaign requests.
     *
     * @return array<string, array<string, mixed>>
     */
    private function validSchedule(): array
    {
        return [
            'monday' => ['enabled' => true, 'time_ranges' => [['id' => '1', 'start_time' => '09:00', 'end_time' => '17:00']]],
            'tuesday' => ['enabled' => true, 'time_ranges' => [['id' => '1', 'start_time' => '09:00', 'end_time' => '17:00']]],
            'wednesday' => ['enabled' => true, 'time_ranges' => [['id' => '1', 'start_time' => '09:00', 'end_time' => '17:00']]],
            'thursday' => ['enabled' => true, 'time_ranges' => [['id' => '1', 'start_time' => '09:00', 'end_time' => '17:00']]],
            'friday' => ['enabled' => true, 'time_ranges' => [['id' => '1', 'start_time' => '09:00', 'end_time' => '17:00']]],
            'saturday' => ['enabled' => false, 'time_ranges' => []],
            'sunday' => ['enabled' => false, 'time_ranges' => []],
        ];
    }

    /**
     * Return a valid campaign payload with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validCampaignPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Campaign',
            'routing_destination_type' => RoutingDestinationType::HANGUP->value,
            'dial_timeout' => 60,
            'destination_connect' => 'connected',
            'caller_id' => '+14155551234',
            'max_dial_attempts' => 3,
            'concurrent_active_calls' => 5,
            'schedule' => $this->validSchedule(),
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->addDays(30)->format('Y-m-d'),
            'timezone' => 'America/New_York',
        ], $overrides);
    }

    // ==================== INDEX ====================

    public function test_index_returns_campaigns_list(): void
    {
        AutoDialerCampaign::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_index_returns_empty_when_no_campaigns(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE->value,
        ]);

        AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT->value,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns?status='.CampaignStatus::ACTIVE->value);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auto-dialer-campaigns');

        $response->assertUnauthorized();
    }

    public function test_index_scopes_by_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== STORE ====================

    public function test_store_creates_new_campaign(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'name' => 'Test Campaign',
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Campaign');

        $this->assertDatabaseHas('auto_dialer_campaigns', [
            'name' => 'Test Campaign',
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT->value,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'routing_destination_type',
                'routing_destination_id',
                'dial_timeout',
                'destination_connect',
                'caller_id',
                'max_dial_attempts',
                'concurrent_active_calls',
                'schedule',
                'start_date',
                'end_date',
                'timezone',
            ]);
    }

    public function test_store_validates_name_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'name' => str_repeat('a', 256),
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_concurrent_active_calls_range(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'concurrent_active_calls' => 0,
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['concurrent_active_calls']);
    }

    public function test_store_validates_caller_id_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'caller_id' => 'invalid-phone',
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['caller_id']);
    }

    public function test_store_allows_valid_e164_caller_id(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'caller_id' => '+14155551234',
            ]));

        $response->assertCreated();
    }

    public function test_store_saves_concurrent_active_calls(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/auto-dialer-campaigns', $this->validCampaignPayload([
                'concurrent_active_calls' => 10,
            ]));

        $response->assertCreated();

        $campaign = AutoDialerCampaign::first();
        $this->assertEquals(10, $campaign->concurrent_active_calls);
    }

    // ==================== SHOW ====================

    public function test_show_returns_campaign_details(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Campaign',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.name', 'Test Campaign');
    }

    public function test_show_returns_404_for_nonexistent_campaign(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/99999');

        $response->assertNotFound();
    }

    public function test_show_returns_404_for_other_organization_campaign(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertNotFound();
    }

    public function test_show_includes_destinations_count(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'total_destinations' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertOk()
            ->assertJsonPath('data.statistics.total_destinations', 5);
    }

    // ==================== UPDATE ====================

    public function test_update_modifies_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/auto-dialer-campaigns/'.$campaign->id, [
                'name' => 'New Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $campaign->refresh();
        $this->assertEquals('New Name', $campaign->name);
    }

    public function test_update_returns_404_for_other_organization_campaign(): void
    {
        $otherOrganization = Organization::factory()->create();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/auto-dialer-campaigns/'.$campaign->id, [
                'name' => 'New Name',
            ]);

        $response->assertNotFound();
    }

    public function test_update_prevents_modifying_status_directly(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT->value,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/auto-dialer-campaigns/'.$campaign->id, [
                'name' => 'Updated',
                'status' => CampaignStatus::ACTIVE->value,
            ]);

        $response->assertOk();

        $campaign->refresh();
        $this->assertEquals(CampaignStatus::DRAFT, $campaign->status);
    }

    public function test_update_validates_fields(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/auto-dialer-campaigns/'.$campaign->id, [
                'concurrent_active_calls' => -1,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['concurrent_active_calls']);
    }

    // ==================== DESTROY ====================

    public function test_destroy_deletes_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertOk();

        $this->assertDatabaseMissing('auto_dialer_campaigns', [
            'id' => $campaign->id,
        ]);
    }

    public function test_destroy_returns_403_for_running_campaign(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::ACTIVE->value,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertForbidden();
    }

    public function test_destroy_returns_404_for_other_organization(): void
    {
        $otherOrganization = Organization::factory()->create();

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertNotFound();
    }

    public function test_destroy_unassigns_list(): void
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => CampaignStatus::DRAFT->value,
        ]);

        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'list_id' => $list->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/auto-dialer-campaigns/'.$campaign->id);

        $response->assertOk();

        $list->refresh();
        $this->assertNull($list->campaign_id);
        $this->assertDatabaseHas('auto_dialer_destinations', [
            'id' => $destination->id,
        ]);
    }
}
