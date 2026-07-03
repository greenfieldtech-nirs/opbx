<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialerWebhookDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_session_lookup(): void
    {
        $organization = Organization::factory()->create();

        $cloudonixSettings = CloudonixSettings::factory()->create([
            'organization_id' => $organization->id,
            'domain_name' => 'test-debug.cloudonix.net',
            'domain_requests_api_key' => 'test-api-key',
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $list = AutoDialerList::factory()->create([
            'organization_id' => $organization->id,
            'campaign_id' => $campaign->id,
        ]);

        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $organization->id,
            'list_id' => $list->id,
        ]);

        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'session_token' => 'test-token-123',
            'status' => 'initiated',
        ]);

        // Verify session exists
        $this->assertDatabaseHas('auto_dialer_call_sessions', [
            'id' => $session->id,
            'session_token' => 'test-token-123',
            'status' => 'initiated',
        ]);

        // Make webhook request
        $response = $this->postJson(
            '/api/webhooks/cloudonix/dialer',
            [
                'type' => 'call.initiated',
                'call_id' => 'test-call-id',
                'session_token' => 'test-token-123',
                'domain' => $cloudonixSettings->domain_name,
            ],
            ['Authorization' => 'Bearer test-api-key']
        );

        $response->assertStatus(200);

        // Check session was updated (bypass organization scope)
        $sessionStatus = OrganizationScope::bypass(fn () => $session->fresh()?->status);
        $this->assertEquals('ringing', $sessionStatus);
    }
}
