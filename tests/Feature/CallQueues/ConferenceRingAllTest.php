<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\CallQueueStrategy;
use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\User;
use App\Services\CallQueue\ConferenceOfferService;
use App\Services\CallQueue\ExtensionPresenceTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conference-mediated ring-all offers (WP3, customer Scenarios 1 + 3):
 * per-agent Cloudonix sessions, synchronous winner election, loser teardown,
 * caller switched into the winner's conference room.
 */
class ConferenceRingAllTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    private User $owner;

    private User $secondAgent;

    private const CALL_ID = 'ed4819f9b58c47e6a8c10d589e959e88';

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral queue state lives in the shared dev Redis.
        foreach (Redis::keys('acd:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'moh_recording_id' => null,
            'strategy' => CallQueueStrategy::RING_ALL,
            'conference_ring_all' => true,
            'agent_ring_timeout' => 15,
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->owner->id,
        ]);

        $this->secondAgent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->secondAgent->id,
            'extension_number' => '1002',
            'type' => ExtensionType::USER,
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->secondAgent->id,
        ]);
    }

    /**
     * Cloudonix + worker fakes: originations return agent-tok-1, agent-tok-2, ...
     */
    private function fakeCloudonix(): void
    {
        $originated = 0;

        Http::fake(function ($request) use (&$originated) {
            if (str_contains($request->url(), 'acd-worker:8084')) {
                return Http::response([], 204);
            }

            // Origination (POST /calls/{domain}/application); session paths
            // (teardown, application switch) also end in /application.
            if (! str_contains($request->url(), '/sessions/') && str_ends_with($request->url(), '/application')) {
                return Http::response(['token' => 'agent-tok-'.(++$originated)], 200);
            }

            return Http::response([], 200);
        });
    }

    private function queueCall(): QueueCall
    {
        return QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'session_token' => self::CALL_ID,
            'from_number' => '9000',
        ]);
    }

    /**
     * @return array<int, array{userId: string, extensionNumber: string}>
     */
    private function agents(): array
    {
        return [
            ['userId' => (string) $this->owner->id, 'extensionNumber' => '1001'],
            ['userId' => (string) $this->secondAgent->id, 'extensionNumber' => '1002'],
        ];
    }

    private function relative(string $url): string
    {
        return str_replace('https://test.example.com', '', $url);
    }

    private function agentAnswers(QueueCall $queueCall, User $agent, string $sessionToken): TestResponse
    {
        $url = app(ConferenceOfferService::class)->signedUrl('voice.queue-agent-connect', $this->queue, $queueCall, $agent->id);

        return $this->post($this->relative($url), ['Session' => $sessionToken]);
    }

    public function test_offer_originates_one_session_per_agent(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();

        $this->assertTrue(app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents()));

        foreach (['1001', '1002'] as $extension) {
            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/application')
                && ! str_contains($request->url(), '/sessions/')
                && $request['destination'] === $extension
                && $request['caller-id'] === '9000'
                && $request['timeout'] === 15
                && str_contains($request['url'], '/voice/queue-agent-connect?sd='));
        }

        $this->assertTrue(app(ConferenceOfferService::class)->isOfferPending(self::CALL_ID));
    }

    public function test_offer_skips_presence_busy_agent(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ExtensionPresenceTracker::class)->handleSessionUpdate($this->organization->id, '1002', 'connected');

        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => ($request['destination'] ?? null) === '1002');
    }

    public function test_offer_is_not_repeated_while_pending(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);

        $offers->offer($this->queue, $queueCall, $this->agents());
        $offers->offer($this->queue, $queueCall, $this->agents());

        Http::assertSentCount(2);
    }

    public function test_first_answering_agent_wins_losers_are_ended_and_caller_joins(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        // The SECOND offered agent answers first: it must be the one credited.
        $response = $this->agentAnswers($queueCall, $this->secondAgent, 'agent-tok-2');

        $content = (string) $response->getContent();
        $room = 'q'.$this->queue->id.'c'.self::CALL_ID;
        $this->assertStringContainsString('<Conference', $content);
        $this->assertStringContainsString($room, $content);

        $queueCall->refresh();
        $this->assertNotNull($queueCall->answered_at);
        $this->assertSame($this->secondAgent->id, $queueCall->agent_user_id);
        $this->assertSame('answered', Redis::get('acd:dialresult:'.self::CALL_ID));

        // Loser session ended, winner session left alone.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-1'));
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-2'));

        // Caller switched into the room; worker told the real winner answered.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/sessions/'.self::CALL_ID.'/application')
            && str_contains($request['url'], '/voice/queue-conference-join?sd='));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'acd-worker:8084/events')
            && $request['type'] === 'answered'
            && $request['agentUserId'] === (string) $this->secondAgent->id);
    }

    public function test_second_answering_agent_hangs_up(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');
        $response = $this->agentAnswers($queueCall, $this->secondAgent, 'agent-tok-2');

        $this->assertStringContainsString('<Hangup/>', (string) $response->getContent());
        $this->assertStringNotContainsString('<Conference', (string) $response->getContent());
        $this->assertSame($this->owner->id, $queueCall->refresh()->agent_user_id);
    }

    public function test_leg_not_in_current_offer_cannot_claim(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        $response = $this->agentAnswers($queueCall, $this->owner, 'stale-token-from-expired-offer');

        $this->assertStringContainsString('<Hangup/>', (string) $response->getContent());
        $this->assertNull($queueCall->refresh()->answered_at);
    }

    public function test_agent_connect_rejects_invalid_signature(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        $url = app(ConferenceOfferService::class)->signedUrl('voice.queue-agent-connect', $this->queue, $queueCall, $this->owner->id);
        $tampered = preg_replace('/sig=[0-9a-f]+/', 'sig='.str_repeat('0', 64), $this->relative($url));

        $response = $this->post($tampered, ['Session' => 'agent-tok-1']);

        $this->assertStringContainsString('<Hangup/>', (string) $response->getContent());
        $this->assertNull($queueCall->refresh()->answered_at);
    }

    public function test_caller_join_returns_room_only_after_a_winner(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        $joinUrl = $this->relative($offers->signedUrl('voice.queue-conference-join', $this->queue, $queueCall));

        $before = (string) $this->post($joinUrl)->getContent();
        $this->assertStringNotContainsString('<Conference', $before, 'no winner yet: caller keeps holding');
        $this->assertStringContainsString('<Redirect', $before);

        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');

        $after = (string) $this->post($joinUrl)->getContent();
        $this->assertStringContainsString('<Conference', $after);
        $this->assertStringContainsString('endConferenceOnExit="true"', $after);
    }

    public function test_cancel_ends_every_pending_agent_session(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());

        $offers->cancel($this->queue, self::CALL_ID);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-1'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-2'));
        $this->assertFalse($offers->isOfferPending(self::CALL_ID));
    }

    public function test_queue_update_persists_conference_ring_all_flag(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/call-queues/{$this->queue->id}", [
            'name' => $this->queue->name,
            'agents' => [$this->owner->id],
            'conference_ring_all' => true,
        ])->assertValid()->assertOk()->assertJsonPath('data.conference_ring_all', true);

        $this->assertTrue($this->queue->refresh()->conference_ring_all);
    }

    public function test_queue_update_without_flag_keeps_it_enabled(): void
    {
        // Update requests inherit create defaults; an omitted flag must not
        // silently switch conference offers off (the UI may not send it).
        $this->queue->update(['conference_ring_all' => true]);
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/call-queues/{$this->queue->id}", [
            'name' => $this->queue->name,
            'agents' => [$this->owner->id],
        ])->assertValid()->assertOk();

        $this->assertTrue($this->queue->refresh()->conference_ring_all);
    }
}
