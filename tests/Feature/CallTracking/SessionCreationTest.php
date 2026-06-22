<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Jobs\ProcessCDRJob;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SessionCreationTest extends TestCase
{
    use RefreshDatabase;

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
            '_organization_id' => $organization->id,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 12345,
                'callStartTime' => now()->getTimestampMs() - 90000,
                'callAnswerTime' => now()->getTimestampMs() - 90000,
                'callEndTime' => now()->getTimestampMs(),
            ],
        ];

        Bus::dispatchSync(new ProcessCDRJob($payload));

        $session = CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->first();
        $this->assertNotNull($session);
        $this->assertSame($campaign->id, $session->call_tracking_campaign_id);
        $this->assertSame('CA12345', $session->call_id);
        $this->assertTrue($session->is_answered);
        $this->assertTrue($session->is_converted);
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
            '_organization_id' => $organization->id,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 67890,
                'callStartTime' => now()->getTimestampMs() - 30000,
                'callAnswerTime' => now()->getTimestampMs() - 30000,
                'callEndTime' => now()->getTimestampMs(),
            ],
        ];

        Bus::dispatchSync(new ProcessCDRJob($payload));

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
            '_organization_id' => $organization->id,
            'timestamp' => now()->timestamp,
            'session' => [
                'id' => 11111,
            ],
        ];

        Bus::dispatchSync(new ProcessCDRJob($payload));

        $this->assertNull(CallTrackingSession::withoutGlobalScope(OrganizationScope::class)->first());
    }
}
