<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Tracking Number API endpoints test suite.
 */
class CallTrackingNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $admin;

    private User $agent;

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

        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    public function test_index_returns_tracking_numbers_for_campaign(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        CallTrackingNumber::factory()->count(2)->forCampaign($campaign)->create();

        $response = $this->getJson('/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'did_number_id',
                        'phone_number',
                        'status',
                    ],
                ],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_index_enforces_tenant_isolation(): void
    {
        Sanctum::actingAs($this->owner);

        $otherCampaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson('/api/v1/call-tracking-campaigns/'.$otherCampaign->id.'/call-tracking-numbers');

        $response->assertStatus(404);
    }

    public function test_owner_can_create_tracking_number(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+15551234567',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers', [
            'did_number_id' => $did->id,
            'friendly_name' => 'Main PPC Number',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.did_number_id', $did->id)
            ->assertJsonPath('data.phone_number', '+15551234567')
            ->assertJsonPath('data.friendly_name', 'Main PPC Number')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('call_tracking_numbers', [
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
        ]);

        $did->refresh();
        $this->assertSame('call_tracking', $did->routing_type);
        $this->assertSame($campaign->id, $did->routing_config['call_tracking_campaign_id']);
    }

    public function test_agent_cannot_create_tracking_number(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers', [
            'did_number_id' => $did->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_create_rejects_did_from_other_organization(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $otherDid = DidNumber::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers', [
            'did_number_id' => $otherDid->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['did_number_id']);
    }

    public function test_create_rejects_duplicate_did_number(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        CallTrackingNumber::factory()->create([
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->postJson('/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers', [
            'did_number_id' => $did->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['did_number_id']);
    }

    public function test_owner_can_update_tracking_number(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers/'.$number->id,
            [
                'friendly_name' => 'Updated Name',
                'status' => 'inactive',
            ]
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.friendly_name', 'Updated Name')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_agent_cannot_update_tracking_number(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers/'.$number->id,
            ['friendly_name' => 'Hacked']
        );

        $response->assertStatus(403);
    }

    public function test_update_rejects_number_from_other_campaign(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $otherCampaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $number = CallTrackingNumber::factory()->forCampaign($otherCampaign)->create();

        $response = $this->putJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers/'.$number->id,
            ['friendly_name' => 'Should fail']
        );

        $response->assertStatus(403);
    }

    public function test_owner_can_delete_tracking_number(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $response = $this->deleteJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers/'.$number->id
        );

        $response->assertStatus(204);
        $this->assertDatabaseMissing('call_tracking_numbers', ['id' => $number->id]);
    }

    public function test_agent_cannot_delete_tracking_number(): void
    {
        Sanctum::actingAs($this->agent);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $number = CallTrackingNumber::factory()->forCampaign($campaign)->create();

        $response = $this->deleteJson(
            '/api/v1/call-tracking-campaigns/'.$campaign->id.'/call-tracking-numbers/'.$number->id
        );

        $response->assertStatus(403);
    }
}
