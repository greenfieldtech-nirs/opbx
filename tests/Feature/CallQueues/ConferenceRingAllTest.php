<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\CallQueueStrategy;
use App\Enums\ExtensionType;
use App\Enums\QueueCallDisposition;
use App\Enums\UserRole;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Services\CallQueue\ConferenceOfferService;
use App\Services\CallQueue\ExtensionPresenceTracker;
use App\Services\CallQueue\ImmediateConnectService;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
    /**
     * @param  array<string, mixed>  $workerPoll  acd-worker /queue/poll decision
     * @param  array<string, mixed>  $workerLive  acd-worker /queue/live snapshot
     */
    private function fakeCloudonix(array $workerPoll = ['action' => 'wait', 'position' => 1], array $workerLive = []): void
    {
        $originated = 0;

        Http::fake(function ($request) use (&$originated, $workerPoll, $workerLive) {
            if (str_contains($request->url(), 'acd-worker:8084/queue/poll')) {
                return Http::response($workerPoll, 200);
            }

            if (str_contains($request->url(), 'acd-worker:8084/queue/live')) {
                return Http::response($workerLive, 200);
            }

            if (str_contains($request->url(), 'acd-worker:8084')) {
                return Http::response(['position' => 1], 200);
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
            'entered_at' => now()->subSeconds(5),
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

        // The winner holds until the caller is in the room (it never starts the room itself).
        $content = (string) $response->getContent();
        $this->assertLessThanOrEqual(16, strlen(app(ConferenceOfferService::class)->roomName($queueCall)));
        $this->assertStringNotContainsString('<Conference', $content);
        $this->assertStringContainsString('<Pause length="1"/>', $content);
        $this->assertStringContainsString('/voice/queue-agent-join?sd=', $content);

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

    private function agentJoinUrl(QueueCall $queueCall, User $agent, int $check = 2): string
    {
        return $this->relative(app(ConferenceOfferService::class)
            ->signedUrl('voice.queue-agent-join', $this->queue, $queueCall, $agent->id, ['check' => $check]));
    }

    public function test_winner_joins_the_room_only_after_the_caller_was_sent_into_it(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');

        $waiting = (string) $this->post($this->agentJoinUrl($queueCall, $this->owner), ['Session' => 'agent-tok-1'])->getContent();
        $this->assertStringNotContainsString('<Conference', $waiting, 'caller not in the room yet: agent keeps holding');
        $this->assertStringContainsString('<Pause length="1"/>', $waiting);
        preg_match('#<Redirect>([^<]+)</Redirect>#', $waiting, $next);
        $context = $offers->verifiedContext(...$this->signedParts(html_entity_decode($next[1])));
        $this->assertSame(3, $context['check'], 'each hold carries the next check number');

        // The caller is served the room join (application switch or poll).
        $this->post($this->relative($offers->signedUrl('voice.queue-conference-join', $this->queue, $queueCall)));

        $joined = (string) $this->post($this->agentJoinUrl($queueCall, $this->owner), ['Session' => 'agent-tok-1'])->getContent();
        $this->assertStringContainsString('>q'.$queueCall->id.'</Conference>', $joined);
    }

    public function test_agent_join_hangs_up_a_leg_that_is_not_the_winner_or_whose_caller_left(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');

        $notWinner = (string) $this->post($this->agentJoinUrl($queueCall, $this->secondAgent), ['Session' => 'agent-tok-2'])->getContent();
        $this->assertStringContainsString('<Hangup/>', $notWinner);

        $queueCall->update(['disposition' => 'abandoned']);
        $callerGone = (string) $this->post($this->agentJoinUrl($queueCall, $this->owner), ['Session' => 'agent-tok-1'])->getContent();
        $this->assertStringContainsString('<Hangup/>', $callerGone);
    }

    public function test_agent_waiting_too_long_is_released_and_the_call_re_offered(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');

        $response = (string) $this->post($this->agentJoinUrl($queueCall, $this->owner, 30), ['Session' => 'agent-tok-1'])->getContent();

        $this->assertStringContainsString('<Hangup/>', $response);
        $this->assertNull($offers->winnerOf(self::CALL_ID));
        $this->assertNull($queueCall->fresh()->answered_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'acd-worker:8084/events') && $request['type'] === 'requeue');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function signedParts(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [(string) $query['sd'], (string) $query['sig']];
    }

    private function dialDecision(): array
    {
        return ['action' => 'dial', 'agents' => $this->agents()];
    }

    private function pollRequest(): QueueCallbackRequest
    {
        return QueueCallbackRequest::create('/callbacks/voice/queue-poll', 'POST', [
            'CallSid' => self::CALL_ID,
            '_organization_id' => $this->organization->id,
            'session_data' => json_encode([
                'call_queue_id' => $this->queue->id,
                'call_id' => self::CALL_ID,
                'organization_id' => $this->organization->id,
                'callback_type' => 'queue_poll',
            ]),
        ]);
    }

    private function originations(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST'
            && ! str_contains($request->url(), '/sessions/')
            && str_ends_with($request->url(), '/application'))->count();
    }

    public function test_route_with_available_agents_rings_agent_sessions_and_holds_caller(): void
    {
        $this->queue->update(['immediate_connect' => true]);
        $this->fakeCloudonix($this->dialDecision());

        $response = app(QueueRoutingStrategy::class)->route(Request::create('/voice/route', 'POST', [
            'CallSid' => self::CALL_ID,
            'Session' => self::CALL_ID,
            'From' => '9000',
            'To' => '525591153987',
            '_organization_id' => $this->organization->id,
        ]), new DidNumber, ['call_queue' => $this->queue]);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('<Number>', $content, 'no multi-target Dial in the caller session');
        $this->assertStringContainsString('<Redirect', $content, 'caller keeps holding in the poll loop');
        $this->assertSame(2, $this->originations());
    }

    public function test_flag_off_keeps_legacy_multi_number_dial(): void
    {
        $this->queue->update(['immediate_connect' => true, 'conference_ring_all' => false]);
        $this->fakeCloudonix($this->dialDecision());

        $response = app(QueueRoutingStrategy::class)->route(Request::create('/voice/route', 'POST', [
            'CallSid' => self::CALL_ID,
            'From' => '9000',
            'To' => '525591153987',
            '_organization_id' => $this->organization->id,
        ]), new DidNumber, ['call_queue' => $this->queue]);

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Number>1001</Number>', $content);
        $this->assertStringContainsString('<Number>1002</Number>', $content);
        $this->assertSame(0, $this->originations());
    }

    public function test_poll_dial_decision_starts_conference_offer(): void
    {
        $this->fakeCloudonix($this->dialDecision());
        $this->queueCall();

        $content = (string) app(QueuePollController::class)->handle($this->pollRequest())->getContent();

        $this->assertStringNotContainsString('<Number>', $content);
        $this->assertStringContainsString('<Redirect', $content);
        $this->assertSame(2, $this->originations());
    }

    public function test_poll_while_offer_pending_holds_without_new_offer(): void
    {
        $this->fakeCloudonix($this->dialDecision());
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        $content = (string) app(QueuePollController::class)->handle($this->pollRequest())->getContent();

        $this->assertStringContainsString('<Redirect', $content);
        $this->assertSame(2, $this->originations(), 'no second round of agent sessions');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/queue/poll'));
    }

    public function test_poll_after_winner_serves_room_join_not_a_new_offer(): void
    {
        // Covers a missed application switch and stale hold documents: the
        // caller is sent into the room, never offered to agents again.
        $this->fakeCloudonix($this->dialDecision());
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');

        $content = (string) app(QueuePollController::class)->handle($this->pollRequest())->getContent();

        $this->assertStringContainsString('<Conference', $content);
        $this->assertStringContainsString('>q'.$queueCall->id.'</Conference>', $content);
        $this->assertStringNotContainsString('<Number>', $content);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/queue/poll'));
    }

    public function test_caller_abandon_during_offer_ends_agent_sessions(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => ['token' => self::CALL_ID, 'callEndTime' => now()->getTimestampMs()],
        ]);

        $this->assertSame(QueueCallDisposition::ABANDONED, $queueCall->refresh()->disposition);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-1'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-2'));
    }

    public function test_answered_conference_call_finalizes_with_real_winner_and_wrap_up(): void
    {
        $this->queue->update(['wrap_up_seconds' => 30]);
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->secondAgent, 'agent-tok-2');

        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => ['token' => self::CALL_ID, 'callEndTime' => now()->addMinute()->getTimestampMs()],
        ]);

        $queueCall->refresh();
        $this->assertSame(QueueCallDisposition::ANSWERED, $queueCall->disposition);
        $this->assertSame($this->secondAgent->id, $queueCall->agent_user_id);

        // Wrap-up goes to the agent who actually took the call.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'acd-worker:8084/events')
            && $request['type'] === 'ended'
            && $request['agentUserId'] === (string) $this->secondAgent->id
            && $request['wrapUpSeconds'] === 30);
    }

    public function test_overflow_ends_pending_agent_sessions(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        app(ConferenceOfferService::class)->offer($this->queue, $queueCall, $this->agents());

        app(QueueCallLifecycleService::class)->markOverflowed($queueCall);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-1'));
        $this->assertFalse(app(ConferenceOfferService::class)->isOfferPending(self::CALL_ID));
    }

    public function test_agent_login_offers_conference_without_application_switch(): void
    {
        $this->queue->update(['immediate_connect' => true]);
        $this->fakeCloudonix($this->dialDecision(), [
            'waiting' => [['callId' => self::CALL_ID, 'position' => 1, 'waitedSeconds' => 5]],
            'agents' => [],
        ]);
        $this->queueCall();

        app(ImmediateConnectService::class)->connectAvailableCallers($this->queue);

        $this->assertSame(2, $this->originations());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sessions/'.self::CALL_ID.'/application'));
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

    public function test_overlapping_offers_originate_once(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);

        // Another request is mid-origination: its lock exists, the offer hash does not yet.
        Redis::set('acd:confoffer:lock:'.self::CALL_ID, '1', 'EX', 30, 'NX');

        $this->assertTrue($offers->offer($this->queue, $queueCall, $this->agents()));

        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), '/sessions/')
            && str_ends_with($request->url(), '/application'));
        $this->assertFalse($offers->isOfferPending(self::CALL_ID));
    }

    public function test_failed_origination_releases_the_lock_so_the_next_poll_can_retry(): void
    {
        Http::fake(fn () => Http::response([], 200)); // no token: nothing originated
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);

        $this->assertFalse($offers->offer($this->queue, $queueCall, $this->agents()));
        $this->assertSame(0, Redis::exists('acd:confoffer:lock:'.self::CALL_ID));
    }

    public function test_claim_keeps_no_winner_when_the_bridge_write_fails(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());

        $lifecycle = $this->createMock(QueueCallLifecycleService::class);
        $lifecycle->method('markConferenceBridge')->willThrowException(new \RuntimeException('db down'));
        $failing = new ConferenceOfferService(app(ExtensionPresenceTracker::class), $lifecycle);

        try {
            $failing->claim($this->queue, $queueCall, $this->owner->id, 'agent-tok-1');
            $this->fail('the bridge failure must propagate');
        } catch (\RuntimeException) {
        }

        $this->assertNull($offers->winnerOf(self::CALL_ID), 'a failed claim must not leave the call stuck as won');
        $this->assertTrue($offers->claim($this->queue, $queueCall, $this->owner->id, 'agent-tok-1'), 'the leg can claim again');
    }

    public function test_claim_after_the_caller_left_cancels_the_offer_and_clears_the_winner(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        QueueCall::withoutGlobalScopes()->whereKey($queueCall->id)->update(['disposition' => QueueCallDisposition::ABANDONED->value]);

        $this->assertFalse($offers->claim($this->queue, $queueCall, $this->owner->id, 'agent-tok-1'));

        $this->assertNull($offers->winnerOf(self::CALL_ID));
        $this->assertFalse($offers->isOfferPending(self::CALL_ID));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/agent-tok-2'));
    }

    public function test_winner_leg_ending_before_the_caller_joined_releases_the_winner_and_reoffers(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');
        $this->assertSame($this->owner->id, $offers->winnerOf(self::CALL_ID));

        // The winner hangs up before the caller was served the room join.
        $leg = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => 'agent-tok-1',
            'status' => 'completed',
        ]);
        app(QueueCallLifecycleService::class)->handleSessionUpdate($leg);
        app(QueueCallLifecycleService::class)->handleSessionUpdate($leg); // duplicate delivery

        $this->assertNull($offers->winnerOf(self::CALL_ID));
        $queueCall->refresh();
        $this->assertNull($queueCall->answered_at);
        $this->assertNull($queueCall->agent_user_id);
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'acd-worker:8084/events')
            && $request['type'] === 'requeue'
            && $request['agentUserId'] === (string) $this->owner->id)->count());

        // The next poll's offer is accepted again (lock and offer cleared).
        $this->assertTrue($offers->offer($this->queue, $queueCall, $this->agents()));
        $this->assertTrue($offers->isOfferPending(self::CALL_ID));
    }

    public function test_winner_leg_ending_after_the_caller_joined_is_an_ordinary_end_of_call(): void
    {
        $this->fakeCloudonix();
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);
        $offers->offer($this->queue, $queueCall, $this->agents());
        $this->agentAnswers($queueCall, $this->owner, 'agent-tok-1');
        $callerCxml = (string) $this->post($this->relative($offers->signedUrl('voice.queue-conference-join', $this->queue, $queueCall)))->getContent();
        $agentCxml = (string) $this->post($this->agentJoinUrl($queueCall, $this->owner), ['Session' => 'agent-tok-1'])->getContent();

        // The agent leaving ends the room, and the caller has no verb after the room: the call ends
        // instead of returning to the queue.
        $this->assertStringContainsString('endConferenceOnExit="true"', $agentCxml);
        $this->assertStringContainsString('endConferenceOnExit="true"', $callerCxml);
        $this->assertMatchesRegularExpression('#</Dial>\s*</Response>#', $callerCxml);
        $this->assertStringNotContainsString('<Redirect', $callerCxml);

        app(QueueCallLifecycleService::class)->handleSessionUpdate(SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => 'agent-tok-1',
            'status' => 'completed',
        ]));

        $this->assertSame($this->owner->id, $offers->winnerOf(self::CALL_ID));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'acd-worker:8084/events') && $request['type'] === 'requeue');
    }

    public function test_one_failed_origination_still_offers_the_other_agents(): void
    {
        $originated = 0;
        Http::fake(function ($request) use (&$originated) {
            if (! str_contains($request->url(), '/sessions/') && str_ends_with($request->url(), '/application')) {
                // The first agent's origination is rejected by Cloudonix.
                return ++$originated === 1 ? Http::response([], 500) : Http::response(['token' => 'agent-tok-ok'], 200);
            }

            return Http::response([], 200);
        });
        $queueCall = $this->queueCall();
        $offers = app(ConferenceOfferService::class);

        $this->assertTrue($offers->offer($this->queue, $queueCall, $this->agents()));

        $this->assertSame(2, $originated, 'both legs were attempted');
        $this->assertTrue($offers->isOfferPending(self::CALL_ID));
        $this->assertSame(['agent-tok-ok'], array_values(Redis::hgetall('acd:confoffer:'.self::CALL_ID)));
    }
}
