<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Call Tracking Session API endpoints test suite.
 */
class CallTrackingSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

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

        $this->otherOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    /**
     * Create a call tracking session bypassing the organization scope.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createSession(CallTrackingCampaign $campaign, CallTrackingNumber $number, array $overrides = []): CallTrackingSession
    {
        return OrganizationScope::bypass(fn () => CallTrackingSession::factory()
            ->forCampaignAndNumber($campaign, $number)
            ->create($overrides));
    }

    public function test_index_returns_sessions_for_organization(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $did = DidNumber::factory()->create(['organization_id' => $this->organization->id]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->forDid($did)->create();

        $this->createSession($campaign, $number);
        $this->createSession($campaign, $number);
        $this->createSession($campaign, $number);

        $otherCampaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);
        $otherDid = DidNumber::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $otherNumber = CallTrackingNumber::factory()->forCampaign($otherCampaign)->forDid($otherDid)->create();

        $this->createSession($otherCampaign, $otherNumber);
        $this->createSession($otherCampaign, $otherNumber);

        $response = $this->getJson('/api/v1/call-tracking-sessions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'call_tracking_campaign_id',
                        'call_tracking_number_id',
                        'did_number_id',
                        'call_id',
                        'caller_number',
                        'called_number',
                        'campaign' => ['id', 'name'],
                        'did' => ['id', 'phone_number'],
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_index_filters_by_campaign_ids(): void
    {
        Sanctum::actingAs($this->owner);

        $campaignA = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $campaignB = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $numberA = CallTrackingNumber::factory()->forCampaign($campaignA)->forDid(
            DidNumber::factory()->create(['organization_id' => $this->organization->id])
        )->create();
        $numberB = CallTrackingNumber::factory()->forCampaign($campaignB)->forDid(
            DidNumber::factory()->create(['organization_id' => $this->organization->id])
        )->create();

        $this->createSession($campaignA, $numberA);
        $this->createSession($campaignB, $numberB);

        $response = $this->getJson('/api/v1/call-tracking-sessions?campaign_ids[]='.$campaignA->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.call_tracking_campaign_id', $campaignA->id);
    }

    public function test_index_searches_by_caller_number(): void
    {
        Sanctum::actingAs($this->owner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $did = DidNumber::factory()->create(['organization_id' => $this->organization->id]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->forDid($did)->create();

        $this->createSession($campaign, $number, ['caller_number' => '+15551234567']);
        $this->createSession($campaign, $number, ['caller_number' => '+15559998888']);

        $response = $this->getJson('/api/v1/call-tracking-sessions?search=12345');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.caller_number', '+15551234567');
    }

    public function test_index_enforces_tenant_isolation(): void
    {
        Sanctum::actingAs($this->otherOwner);

        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $did = DidNumber::factory()->create(['organization_id' => $this->organization->id]);
        $number = CallTrackingNumber::factory()->forCampaign($campaign)->forDid($did)->create();

        $this->createSession($campaign, $number);
        $this->createSession($campaign, $number);
        $this->createSession($campaign, $number);

        $response = $this->getJson('/api/v1/call-tracking-sessions');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
