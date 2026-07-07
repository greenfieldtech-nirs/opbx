<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Enums\CallTrackingCampaignStatus;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DniSwapTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_and_medium_match_returns_correct_tracking_number(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Google Search Campaign',
            'source' => 'google',
            'medium' => 'cpc',
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551234',
            'status' => 'active',
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'utm_source' => 'Google',
            'utm_medium' => 'CPC',
        ]));

        $response->assertOk()
            ->assertJson([
                'tracking_number' => '+14155551234',
                'campaign_id' => $campaign->id,
                'campaign_name' => 'Google Search Campaign',
                'source' => 'google',
                'medium' => 'cpc',
            ]);
    }

    public function test_utm_campaign_filters_campaigns_by_name_case_insensitive(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $matchingCampaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Summer Sale 2026',
            'source' => 'google',
            'medium' => 'cpc',
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Generic Brand Campaign',
            'source' => 'google',
            'medium' => 'cpc',
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155555678',
            'status' => 'active',
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $matchingCampaign->id,
            'did_number_id' => $did->id,
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'SUMMER',
        ]));

        $response->assertOk()
            ->assertJson([
                'tracking_number' => '+14155555678',
                'campaign_id' => $matchingCampaign->id,
                'campaign_name' => 'Summer Sale 2026',
            ]);
    }

    public function test_no_match_returns_default_number(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'utm_source' => 'facebook',
            'utm_medium' => 'social',
            'default_number' => '+14155550000',
        ]));

        $response->assertOk()
            ->assertJson([
                'tracking_number' => '+14155550000',
                'campaign_id' => null,
                'campaign_name' => null,
                'source' => null,
                'medium' => null,
            ]);
    }

    public function test_no_match_without_default_returns_null_tracking_number(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]));

        $response->assertOk()
            ->assertJson([
                'tracking_number' => null,
                'campaign_id' => null,
                'campaign_name' => null,
                'source' => null,
                'medium' => null,
            ]);
    }

    public function test_endpoint_does_not_require_authentication(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
        ]));

        $response->assertOk();
    }

    public function test_organization_id_is_required(): void
    {
        $response = $this->getJson(route('call-tracking.dni.swap'));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_id']);
    }

    public function test_default_number_must_be_e164(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'default_number' => 'not-a-number',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['default_number']);
    }

    public function test_inactive_campaigns_are_not_matched_but_active_number_falls_back(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'source' => 'google',
            'medium' => 'cpc',
            'status' => CallTrackingCampaignStatus::INACTIVE,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551111',
            'status' => 'active',
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ]);

        $response = $this->getJson(route('call-tracking.dni.swap', [
            'organization_id' => $organization->id,
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'default_number' => '+14155552222',
        ]));

        $response->assertOk()
            ->assertJson([
                'tracking_number' => '+14155551111',
                'campaign_id' => null,
                'campaign_name' => null,
                'source' => null,
                'medium' => null,
            ]);
    }
}
