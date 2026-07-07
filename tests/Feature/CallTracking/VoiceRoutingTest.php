<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CloudonixSettings $settings;

    private string $apiKey = 'test-call-tracking-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);

        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_name' => 'test.example.com',
            'domain_requests_api_key' => $this->apiKey,
        ]);
    }

    public function test_inbound_call_to_call_tracking_did_returns_forward_cxml(): void
    {
        $campaign = CallTrackingCampaign::factory()->forwardTo('+14155559876')->create([
            'organization_id' => $this->organization->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+14155551234',
            'status' => 'active',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1987654321',
            'To' => $did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA'.md5(uniqid()),
            'Domain' => 'test.example.com',
        ], [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/xml',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('+14155559876', $content);
    }

    public function test_inactive_campaign_returns_unavailable_cxml(): void
    {
        $campaign = CallTrackingCampaign::factory()->forwardTo('+14155559876')->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+14155555678',
            'status' => 'active',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1987654321',
            'To' => $did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA'.md5(uniqid()),
            'Domain' => 'test.example.com',
        ], [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/xml',
        ]);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Campaign is inactive', $content);
    }
}
