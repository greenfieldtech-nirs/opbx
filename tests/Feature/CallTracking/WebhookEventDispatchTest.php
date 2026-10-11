<?php

declare(strict_types=1);

namespace Tests\Feature\CallTracking;

use App\Jobs\DispatchCallTrackingWebhookJob;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingNumber;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEventDispatchTest extends TestCase
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

    public function test_cdr_dispatch_webhook_job_for_converted_event(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create(['status' => 'active']);
        $campaign = CallTrackingCampaign::factory()->create([
            'organization_id' => $organization->id,
            'conversion_rule' => [
                'min_answered_duration_seconds' => 60,
                'requires_answered_disposition' => true,
            ],
        ]);

        CallTrackingNotificationSettings::create([
            'organization_id' => $organization->id,
            'call_tracking_campaign_id' => $campaign->id,
            'webhook_url' => 'https://example.com/webhook',
            'auth_method' => 'none',
            'auth_secret' => null,
            'auth_username' => null,
            'enabled_events' => ['call.received', 'call.converted'],
            'is_active' => true,
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

        Queue::assertPushed(DispatchCallTrackingWebhookJob::class, function (DispatchCallTrackingWebhookJob $job) {
            return $job->eventType === 'call.converted';
        });
    }
}
