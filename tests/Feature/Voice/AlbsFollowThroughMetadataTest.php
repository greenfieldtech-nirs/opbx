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
use App\Services\VoiceRouting\AlbsDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
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

    public function test_follow_through_does_not_leak_metadata_across_organizations(): void
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

        // Create a session belonging to a different organization with the same CallSid.
        $otherOrganization = Organization::factory()->create();
        $otherSettings = CloudonixSettings::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);
        $otherDestination = AutoDialerDestination::factory()->create([
            'organization_id' => $otherOrganization->id,
            'metadata' => ['stolen' => 'value'],
        ]);
        $otherCampaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);
        AutoDialerCallSession::factory()->create([
            'organization_id' => $otherOrganization->id,
            'campaign_id' => $otherCampaign->id,
            'destination_id' => $otherDestination->id,
            'call_id' => 'CA123',
        ]);

        // Send a request authenticated for our organization, but with the other org's CallSid.
        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $aiAssistant->id,
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
        $cxml = $response->getContent();
        $this->assertStringNotContainsString('<Header name="X-stolen"', $cxml);
    }

    public function test_first_pick_and_follow_through_share_one_round_robin_counter_with_a_ttl(): void
    {
        $assistants = AiAssistant::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'round_robin',
            'follow_through' => true,
        ]);
        foreach ($assistants as $i => $assistant) {
            AiAssistantLoadBalancerMember::factory()->create([
                'load_balancer_id' => $loadBalancer->id,
                'ai_assistant_id' => $assistant->id,
                'position' => $i + 1,
                'status' => 'active',
            ]);
        }
        $key = "albs:rr:{$loadBalancer->id}";
        Redis::del($key);

        $first = app(AlbsDistributionService::class)->selectAssistant($loadBalancer);
        $this->assertSame(1, (int) Redis::get($key));

        $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $loadBalancer->id,
            'current_assistant_id' => $first->id,
        ]), [
            'CallSid' => 'CA-RR-1',
            'DialCallStatus' => 'busy',
            'Domain' => $this->settings->domain_uuid,
            'To' => '+15551234567',
            'From' => '+15559876543',
        ], ['Authorization' => 'Bearer '.$this->settings->domain_requests_api_key])->assertOk();

        $this->assertSame(2, (int) Redis::get($key));
        $this->assertGreaterThan(0, Redis::ttl($key));
        Redis::del($key);
    }

    public function test_selection_skips_excluded_assistants(): void
    {
        $assistants = AiAssistant::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $loadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->organization->id,
            'strategy' => 'priority',
        ]);
        foreach ($assistants as $i => $assistant) {
            AiAssistantLoadBalancerMember::factory()->create([
                'load_balancer_id' => $loadBalancer->id,
                'ai_assistant_id' => $assistant->id,
                'priority' => $i + 1,
                'status' => 'active',
            ]);
        }

        $service = app(AlbsDistributionService::class);

        $this->assertSame($assistants[1]->id, $service->selectAssistant($loadBalancer, [$assistants[0]->id])->id);
        $this->assertNull($service->selectAssistant($loadBalancer, [$assistants[0]->id, $assistants[1]->id]));
    }

    public function test_follow_through_refuses_a_load_balancer_of_another_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherAssistant = AiAssistant::factory()->create([
            'organization_id' => $otherOrganization->id,
            'provider' => 'retell',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773456'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $nextAssistant = AiAssistant::factory()->create([
            'organization_id' => $otherOrganization->id,
            'provider' => 'vapi',
            'protocol' => 'sip',
            'configuration' => ['phone_number' => '+12127773457'],
            'status' => AiAssistantStatus::ACTIVE,
        ]);
        $otherLoadBalancer = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $otherOrganization->id,
            'strategy' => 'priority',
            'follow_through' => true,
        ]);
        foreach ([$otherAssistant, $nextAssistant] as $i => $assistant) {
            AiAssistantLoadBalancerMember::factory()->create([
                'load_balancer_id' => $otherLoadBalancer->id,
                'ai_assistant_id' => $assistant->id,
                'priority' => $i + 1,
                'status' => 'active',
            ]);
        }

        // Authenticated as our organization; the query string also tries to claim the other one.
        $response = $this->postJson(route('voice.albs-follow-through', [
            'albs_id' => $otherLoadBalancer->id,
            'current_assistant_id' => $otherAssistant->id,
            '_organization_id' => $otherOrganization->id,
        ]), [
            'CallSid' => 'CA999',
            'DialCallStatus' => 'busy',
            'Domain' => $this->settings->domain_uuid,
            'To' => '+15551234567',
            'From' => '+15559876543',
        ], [
            'X-Cx-Session' => 'CA999',
            'Authorization' => 'Bearer '.$this->settings->domain_requests_api_key,
        ]);

        $response->assertStatus(200);
        $cxml = $response->getContent();
        $this->assertStringNotContainsString('+12127773457', $cxml);
        $this->assertStringContainsString('<Hangup', $cxml);
    }
}
