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
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\CallQueue\ExtensionPresenceTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Wait for available agents": a call queue campaign hands out no destinations, and refuses to place
 * a call, while no agent of its queue is available.
 */
final class CampaignWaitForAgentsTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_TOKEN = 'wait-for-agents-worker-token';

    private Organization $org;

    private CallQueue $queue;

    /** @var array<string, string> extension number => ACD worker state reported by the fake /queue/live */
    private array $states = ['3101' => 'BUSY', '3102' => 'WRAP_UP'];

    private bool $workerDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.dialer_worker.token', self::WORKER_TOKEN);

        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $this->org->id]);
        $this->queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        foreach (array_keys($this->states) as $number) {
            $agent = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
            Extension::factory()->create([
                'organization_id' => $this->org->id, 'user_id' => $agent->id, 'type' => ExtensionType::USER,
                'status' => 'active', 'extension_number' => $number,
            ]);
            CallQueueAgent::factory()->create(['organization_id' => $this->org->id, 'call_queue_id' => $this->queue->id, 'user_id' => $agent->id]);
            Redis::del("acd:presence:{$this->org->id}:{$number}");
        }

        // ACD worker: /queue/live reports $this->states for the roster it is sent; Cloudonix answers 200.
        Http::fake(function (HttpRequest $request) {
            if (! str_ends_with($request->url(), '/queue/live')) {
                return Http::response(['token' => 'sess-1', 'id' => 'call-1'], 200);
            }
            if ($this->workerDown) {
                return Http::response([], 503);
            }

            return Http::response(['waiting' => [], 'agents' => array_map(fn (array $agent) => [
                'userId' => $agent['userId'], 'extensionNumber' => $agent['extensionNumber'], 'state' => $this->states[$agent['extensionNumber']],
            ], $request['agents'])], 200);
        });
    }

    /** @return array{AutoDialerCampaign, AutoDialerDestination} */
    private function campaign(bool $wait, RoutingDestinationType $type = RoutingDestinationType::CALL_QUEUE): array
    {
        $campaign = AutoDialerCampaign::factory()->create([
            'organization_id' => $this->org->id,
            'routing_destination_type' => $type,
            'routing_destination_id' => $this->queue->id,
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

    private function pending(AutoDialerCampaign $campaign): TestResponse
    {
        return $this->worker('GET', "/campaigns/{$campaign->id}/destinations/pending")->assertOk();
    }

    public function test_no_destinations_while_no_agent_is_available_and_dialing_resumes_when_one_is(): void
    {
        [$campaign, $destination] = $this->campaign(wait: true);

        // Busy, in wrap-up, and (below) logged out are all unavailable.
        $this->pending($campaign)->assertJsonPath('meta.waiting_for_agents', true)->assertJsonCount(0, 'data');
        $this->states['3102'] = 'LOGGED_OUT';
        $this->pending($campaign)->assertJsonPath('meta.waiting_for_agents', true)->assertJsonCount(0, 'data');

        $this->states['3102'] = 'AVAILABLE';
        $this->pending($campaign)->assertJsonPath('meta.waiting_for_agents', false)->assertJsonPath('data.0.id', $destination->id);
    }

    public function test_an_available_agent_on_another_call_does_not_count(): void
    {
        [$campaign] = $this->campaign(wait: true);
        $this->states['3102'] = 'AVAILABLE';
        app(ExtensionPresenceTracker::class)->handleSessionUpdate($this->org->id, '+3102', 'connected');

        $this->pending($campaign)->assertJsonPath('meta.waiting_for_agents', true)->assertJsonCount(0, 'data');
    }

    public function test_initiate_is_refused_while_waiting_and_the_destination_stays_pending(): void
    {
        [$campaign, $destination] = $this->campaign(wait: true);

        $this->worker('POST', '/calls/initiate', [
            'campaign_id' => $campaign->id, 'destination_id' => $destination->id, 'phone_number' => $destination->phone_number,
            'worker_id' => 'worker-1', 'initiated_at' => now()->toIso8601String(),
        ])->assertStatus(409);

        Http::assertNotSent(fn (HttpRequest $request) => ! str_ends_with($request->url(), '/queue/live'));
        $this->assertSame(DestinationStatus::PENDING, $destination->fresh()->status);
    }

    public function test_setting_off_not_a_queue_or_worker_unreachable_never_waits(): void
    {
        foreach ([$this->campaign(wait: false)[0], $this->campaign(wait: true, type: RoutingDestinationType::RING_GROUP)[0]] as $campaign) {
            $this->pending($campaign)->assertJsonPath('meta.waiting_for_agents', false)->assertJsonCount(1, 'data');
        }

        $this->workerDown = true;
        $this->pending($this->campaign(wait: true)[0])->assertJsonPath('meta.waiting_for_agents', false)->assertJsonCount(1, 'data');
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
