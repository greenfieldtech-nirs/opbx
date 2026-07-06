<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\AiAssistantStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlbsFollowThroughMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CloudonixSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->organization = Organization::factory()->create();
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://example.com',
        ]);
    }

    public function test_follow_through_includes_metadata_for_next_assistant(): void
    {
        $failedAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $nextAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'vapi',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773457'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'priority',
            'follow_through' => true,
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $failedAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $nextAssistant->id,
            'priority' => 2,
            'status' => 'active',
        ]);

        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->organization->id,
            'metadata' => ['key' => 'value'],
        ]);
        $session = AutoDialerCallSession::factory()->create([
            'organization_id' => $this->organization->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'call_id' => 'CA123',
        ]);

        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $failedAssistant->id,
        ]), [
            'CallSid' => 'CA123',
            'DialCallStatus' => 'busy',
            'Domain' => $this->settings->domain_uuid,
            'To' => '+15551234567',
            'From' => '+15559876543',
        ], [
            'X-Cx-Session' => 'CA123',
            'Authorization' => 'Bearer '.$this->settings->domain_requests_api_key,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $cxml = $response->getContent();
        $this->assertStringContainsString('<Header name="X-key" value="value"/>', $cxml);
    }

    public function test_follow_through_omits_metadata_when_session_missing(): void
    {
        $aiAssistant = AiAssistant::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'priority',
            'follow_through' => true,
        ]);
        AiAssistantLoadBalancerMember::factory()->create([
            'load_balancer_id' => $loadBalancer->id,
            'ai_assistant_id' => $aiAssistant->id,
            'priority' => 1,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $aiAssistant->id,
        ]), [
            'CallSid' => 'UNKNOWN',
            'DialCallStatus' => 'busy',
            'Domain' => $this->settings->domain_uuid,
            'To' => '+15551234567',
            'From' => '+15559876543',
        ], [
            'X-Cx-Session' => 'UNKNOWN',
            'Authorization' => 'Bearer '.$this->settings->domain_requests_api_key,
        ]);

        $response->assertStatus(200);
        $cxml = $response->getContent();
        $this->assertStringNotContainsString('<Header name="X-', $cxml);
    }
}
