<?php

declare(strict_types=1);

namespace Tests\Feature\AutoDialer;

use App\Enums\DestinationStatus;
use App\Enums\ExtensionType;
use App\Enums\ListStatus;
use App\Enums\RoutingDestinationType;
use App\Enums\UserRole;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\CallQueue\ExtensionPresenceTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Wait for available agents": a ring group campaign hands out no destinations, and refuses to place
 * a call, while every agent of its ring group is on a call.
 */
final class CampaignWaitForAgentsTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_TOKEN = 'wait-for-agents-worker-token';

    private const AGENTS = ['3101', '3102'];

    private Organization $org;

    private RingGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.dialer_worker.token', self::WORKER_TOKEN);

        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $this->org->id]);
        $this->group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        foreach (self::AGENTS as $i => $number) {
            $extension = Extension::factory()->create([
                'organization_id' => $this->org->id, 'type' => ExtensionType::USER, 'status' => 'active', 'extension_number' => $number,
            ]);
            RingGroupMember::create(['ring_group_id' => $this->group->id, 'extension_id' => $extension->id, 'priority' => $i + 1]);
            Redis::del("acd:presence:{$this->org->id}:{$number}");
        }
    }

    private function onCall(string $extensionNumber): void
    {
        app(ExtensionPresenceTracker::class)->handleSessionUpdate($this->org->id, '+'.$extensionNumber, 'connected');
    }

    /** @return array{AutoDialerCampaign, AutoDialerDestination} */
    private function campaign(bool $wait, RoutingDestinationType $type = RoutingDestinationType::RING_GROUP): array
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->org->id,
            'routing_destination_type' => $type,
            'routing_destination_id' => $this->group->id,
            'wait_for_available_agents' => $wait,
        ]);
        $list = AutoDialerList::factory()->create([
            'organization_id' => $this->org->id, 'campaign_id' => $campaign->id, 'used_by_campaign_id' => $campaign->id,
            'status' => ListStatus::IN_USE, 'used_at' => now(),
        ]);
        $destination = AutoDialerDestination::factory()->create([
            'organization_id' => $this->org->id, 'list_id' => $list->id, 'status' => DestinationStatus::PENDING,
        ]);

        return [$campaign, $destination];
    }

    private function worker(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::WORKER_TOKEN, 'Accept' => 'application/json'])
            ->json($method, '/api/v1/dialer/worker'.$uri, $data);
    }

    public function test_no_destinations_while_every_agent_is_busy_and_dialing_resumes_when_one_frees_up(): void
    {
        [$campaign, $destination] = $this->campaign(wait: true);

        $this->onCall('3101');
        $this->worker('GET', "/campaigns/{$campaign->id}/destinations/pending")
            ->assertOk()->assertJsonPath('meta.waiting_for_agents', false)->assertJsonCount(1, 'data');

        $this->onCall('3102');
        $this->worker('GET', "/campaigns/{$campaign->id}/destinations/pending")
            ->assertOk()->assertJsonPath('meta.waiting_for_agents', true)->assertJsonCount(0, 'data');

        app(ExtensionPresenceTracker::class)->handleSessionUpdate($this->org->id, '+3102', 'completed', 'deleted');
        $this->worker('GET', "/campaigns/{$campaign->id}/destinations/pending")
            ->assertOk()->assertJsonPath('meta.waiting_for_agents', false)->assertJsonPath('data.0.id', $destination->id);
    }

    public function test_initiate_is_refused_while_waiting_and_the_destination_stays_pending(): void
    {
        [$campaign, $destination] = $this->campaign(wait: true);
        array_map(fn ($n) => $this->onCall($n), self::AGENTS);
        Http::fake();

        $this->worker('POST', '/calls/initiate', [
            'campaign_id' => $campaign->id, 'destination_id' => $destination->id, 'phone_number' => $destination->phone_number,
            'worker_id' => 'worker-1', 'initiated_at' => now()->toIso8601String(),
        ])->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame(DestinationStatus::PENDING, $destination->fresh()->status);
    }

    public function test_setting_off_or_not_a_ring_group_never_waits(): void
    {
        array_map(fn ($n) => $this->onCall($n), self::AGENTS);

        foreach ([$this->campaign(wait: false)[0], $this->campaign(wait: true, type: RoutingDestinationType::CALL_QUEUE)[0]] as $campaign) {
            $this->worker('GET', "/campaigns/{$campaign->id}/destinations/pending")
                ->assertOk()->assertJsonPath('meta.waiting_for_agents', false)->assertJsonCount(1, 'data');
        }
    }

    public function test_the_setting_is_saved_and_returned(): void
    {
        [$campaign] = $this->campaign(wait: false);
        $owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);

        $this->actingAs($owner)->putJson("/api/v1/auto-dialer-campaigns/{$campaign->id}", ['wait_for_available_agents' => true])
            ->assertOk()
            ->assertJsonPath('data.wait_for_available_agents', true);
    }
}
