<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SessionCreationTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $payload */
    private function postCdr(Organization $organization, array $payload): void
    {
        CloudonixSettings::factory()->create([
            'organization_id' => $organization->id,
            'domain_name' => 'tracking.cloudonix.net',
            'domain_requests_api_key' => 'tracking-requests-api-key-1234567890',
        ]);

        $this->postJson('/api/webhooks/cloudonix/cdr', ['domain' => 'tracking.cloudonix.net'] + $payload, [
            'Authorization' => 'Bearer tracking-requests-api-key-1234567890',
        ])->assertOk();
    }

    public function test_cdr_creates_converted_session_for_call_tracking_did(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'conversion_rule' => [
                'min_answered_duration_seconds' => 60,
                'requires_answered_disposition' => true,
            ],
        ]);
        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155551234',
            'status' => 'active',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => 'active',
        ]);

        $payload = [
            'call_id' => 'CA12345',
            'from' => '+1987654321',
            'to' => $did->phone_number,
            'disposition' => 'CONNECTED',
            'duration' => 90,
            'billsec' => 90,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 12345,
                'callStartTime' => now()->getTimestampMs() - 90000,
                'callAnswerTime' => now()->getTimestampMs() - 90000,
                'callEndTime' => now()->getTimestampMs(),
            ],
        ];

        $this->postCdr($organization, $payload);

        $session = CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->first();
        $this->assertNotNull($session);
        $this->assertSame($campaign->id, $session->call_tracking_campaign_id);
        $this->assertSame('CA12345', $session->call_id);
        $this->assertTrue($session->is_answered);
        $this->assertTrue($session->is_converted);
    }

    public function test_replayed_cdr_does_not_create_a_second_session(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create(['organization_id' => $organization->id]);
        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155550001',
            'status' => 'active',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);
        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => 'active',
        ]);
        $payload = ['domain' => 'tracking.cloudonix.net', 'call_id' => 'CA-REPLAY', 'from' => '+1987654321', 'to' => $did->phone_number,
            'disposition' => 'CONNECTED', 'duration' => 90, 'billsec' => 90, 'timestamp' => now()->timestamp];

        $this->postCdr($organization, $payload);
        // A replay reaches the controller only when the first delivery failed (successful
        // responses are served from the idempotency cache): drop that cache to simulate it.
        Cache::flush();
        $this->postJson('/api/webhooks/cloudonix/cdr', $payload, ['Authorization' => 'Bearer tracking-requests-api-key-1234567890'])
            ->assertOk()->assertJson(['message' => 'CDR already recorded']);

        $this->assertSame(1, CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->where('call_id', 'CA-REPLAY')->count());
    }

    public function test_cdr_does_not_convert_when_duration_below_threshold(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'conversion_rule' => [
                'min_answered_duration_seconds' => 60,
                'requires_answered_disposition' => true,
            ],
        ]);
        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'phone_number' => '+14155555678',
            'status' => 'active',
            'routing_type' => 'call_tracking',
            'routing_config' => ['call_tracking_campaign_id' => $campaign->id],
        ]);

        CallTrackingNumber::factory()->create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => $did->id,
            'status' => 'active',
        ]);

        $payload = [
            'call_id' => 'CA67890',
            'from' => '+1987654321',
            'to' => $did->phone_number,
            'disposition' => 'CONNECTED',
            'duration' => 30,
            'billsec' => 30,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 67890,
                'callStartTime' => now()->getTimestampMs() - 30000,
                'callAnswerTime' => now()->getTimestampMs() - 30000,
                'callEndTime' => now()->getTimestampMs(),
            ],
        ];

        $this->postCdr($organization, $payload);

        $session = CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->is_answered);
        $this->assertFalse($session->is_converted);
    }

    public function test_cdr_for_non_tracking_did_does_not_create_session(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);

        $payload = [
            'call_id' => 'CA00000',
            'from' => '+1987654321',
            'to' => '+14155550000',
            'disposition' => 'CONNECTED',
            'duration' => 90,
            'billsec' => 90,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 11111,
            ],
        ];

        $this->postCdr($organization, $payload);

        $this->assertNull(CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->first());
    }
}
