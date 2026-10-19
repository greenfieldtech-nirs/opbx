<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\ExtensionType;
use App\Enums\RingGroupStrategy;
use App\Enums\RoutingDestinationType;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * An auto-dialer campaign can hand answered calls to a ring group or a call queue: the campaign CXML
 * redirects to a signed callback that routes the live session like an inbound call, with the contact
 * as the caller.
 */
final class CampaignRingGroupAndQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN_CALLER_ID = '+14155551234';

    private const CONTACT = '+12025550188';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->org = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => 'campaign.example.com',
            'webhook_base_url' => 'https://hooks.example.com',
        ]);

        // Ring group member originations return a session token; everything else (ACD worker) answers 200.
        Http::fake(fn (HttpRequest $request) => Http::response(
            str_ends_with($request->url(), '/application') ? ['token' => Str::random(32)] : [],
            200
        ));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function campaignCxml(RoutingDestinationType $type, int $destinationId): string
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->org->id,
            'routing_destination_type' => $type,
            'routing_destination_id' => $destinationId,
            'caller_id' => self::CAMPAIGN_CALLER_ID,
            'action_voicemail' => null,
            'action_human' => null,
            'action_unknown' => null,
        ]);
        $destination = AutoDialerDestination::factory()->create(['organization_id' => $this->org->id, 'phone_number' => self::CONTACT]);

        $service = new AutoDialerCloudonixService(Mockery::mock(OutboundRoutingService::class), Mockery::mock(PhoneNumberService::class));
        $method = new \ReflectionMethod($service, 'generateCxmlForCampaign');

        return $method->invoke($service, $campaign, $destination);
    }

    /** The redirect target, as a path the test client can post to. */
    private function redirectPath(string $cxml): string
    {
        $this->assertSame(1, preg_match('#<Redirect>([^<]+)</Redirect>#', $cxml, $match), $cxml);
        $url = html_entity_decode($match[1], ENT_XML1 | ENT_QUOTES);
        $this->assertStringStartsWith('https://hooks.example.com/api/callbacks/voice/campaign-route?', $url);

        return substr($url, strlen('https://hooks.example.com'));
    }

    /** What Cloudonix posts for the answered campaign call: From is the campaign caller ID. */
    private function answeredCall(string $path, string $session): string
    {
        return $this->post($path, [
            'From' => self::CAMPAIGN_CALLER_ID, 'To' => self::CONTACT, 'Direction' => 'outbound-api',
            'CallSid' => $session, 'Session' => $session, 'Domain' => 'campaign.example.com',
        ])->assertOk()->getContent();
    }

    public function test_call_queue_campaign_enqueues_the_contact_as_the_caller(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $session = Str::random(32);

        $xml = $this->answeredCall($this->redirectPath($this->campaignCxml(RoutingDestinationType::CALL_QUEUE, $queue->id)), $session);

        $this->assertStringContainsString('/voice/queue-poll', $xml);
        $queueCall = QueueCall::withoutGlobalScopes()->where('call_id', $session)->firstOrFail();
        $this->assertSame($queue->id, $queueCall->call_queue_id);
        $this->assertSame(self::CONTACT, $queueCall->from_number);
        $this->assertSame($session, $queueCall->session_token);
    }

    public function test_ring_group_campaign_rings_the_members_with_the_contacts_number(): void
    {
        $group = RingGroup::factory()->create([
            'organization_id' => $this->org->id,
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'timeout' => 20,
        ]);
        foreach (['2001', '2002'] as $i => $number) {
            $extension = Extension::factory()->create([
                'organization_id' => $this->org->id, 'type' => ExtensionType::USER, 'status' => 'active',
                'extension_number' => $number, 'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            ]);
            RingGroupMember::create(['ring_group_id' => $group->id, 'extension_id' => $extension->id, 'priority' => $i + 1]);
        }

        $xml = $this->answeredCall($this->redirectPath($this->campaignCxml(RoutingDestinationType::RING_GROUP, $group->id)), Str::random(32));

        $this->assertStringContainsString('<Conference', $xml);
        foreach (['2001', '2002'] as $number) {
            Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/application')
                && $r['destination'] === $number && $r['caller-id'] === self::CONTACT);
        }
    }

    public function test_tampered_or_foreign_destination_hangs_up(): void
    {
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $path = $this->redirectPath($this->campaignCxml(RoutingDestinationType::CALL_QUEUE, $queue->id));

        $tampered = str_replace('destination_id='.$queue->id, 'destination_id='.($queue->id + 1), $path);
        $this->assertStringContainsString('<Hangup', $this->answeredCall($tampered, Str::random(32)));

        // A destination deleted (or never in this organization) after the call was placed.
        $queue->delete();
        $this->assertStringContainsString('<Hangup', $this->answeredCall($path, Str::random(32)));
        $this->assertSame(0, QueueCall::withoutGlobalScopes()->count());
    }

    public function test_campaign_accepts_only_its_own_organizations_ring_groups_and_queues(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        $ownGroup = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $foreignQueue = CallQueue::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $payload = fn (string $type, int $id) => [
            'name' => 'Callback campaign', 'routing_destination_type' => $type, 'routing_destination_id' => $id,
            'dial_timeout' => 30, 'destination_connect' => 'connected', 'caller_id' => self::CAMPAIGN_CALLER_ID,
            'max_dial_attempts' => 1, 'concurrent_active_calls' => 1, 'timezone' => 'UTC',
            'start_date' => now()->format('Y-m-d'), 'end_date' => now()->addDay()->format('Y-m-d'),
            'schedule' => collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                ->mapWithKeys(fn ($day) => [$day => ['enabled' => false, 'time_ranges' => []]])->all(),
        ];

        $this->actingAs($owner)->postJson('/api/v1/auto-dialer-campaigns', $payload('ring_group', $ownGroup->id))
            ->assertCreated()
            ->assertJsonPath('data.routing_destination_label', 'Ring Group');
        $this->actingAs($owner)->postJson('/api/v1/auto-dialer-campaigns', $payload('call_queue', $foreignQueue->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('routing_destination_id');
    }

    public function test_ring_group_or_queue_used_by_a_campaign_cannot_be_deleted(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        foreach ([[RoutingDestinationType::RING_GROUP, $group->id], [RoutingDestinationType::CALL_QUEUE, $queue->id]] as [$type, $id]) {
            AutoDialerCampaign::factory()->create(['organization_id' => $this->org->id, 'routing_destination_type' => $type, 'routing_destination_id' => $id]);
        }

        $this->actingAs($owner)->deleteJson("/api/v1/ring-groups/{$group->id}")->assertStatus(409);
        $this->actingAs($owner)->deleteJson("/api/v1/call-queues/{$queue->id}")->assertStatus(409);
    }
}
