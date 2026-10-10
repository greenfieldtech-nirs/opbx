<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\ExtensionType;
use App\Enums\QueueCallDisposition;
use App\Enums\UserRole;
use App\Http\Controllers\Voice\QueueDialCallbackController;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\ExtensionPresenceTracker;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Call queue voice flow tests: enqueue, hold, poll decisions, overflow,
 * dial callback, and webhook-driven lifecycle.
 */
class QueueCallFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private CallQueue $queue;

    private User $agent;

    private const CALL_ID = 'call-queue-001';

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral queue state (skip markers, presence, dial markers) lives in
        // the shared dev Redis and must not leak between tests or runs.
        foreach (Redis::keys('acd:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->agent->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
        ]);

        $this->queue = CallQueue::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Support',
            'moh_recording_id' => null,
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $this->agent->id,
        ]);
    }

    private function voiceRequest(array $params = []): Request
    {
        return Request::create('/voice/route', 'POST', array_merge([
            'CallSid' => self::CALL_ID,
            'From' => '+15551234567',
            'To' => '8000',
            '_organization_id' => $this->organization->id,
        ], $params));
    }

    private function callbackRequest(array $sessionData, array $extra = []): QueueCallbackRequest
    {
        return QueueCallbackRequest::create('/callbacks/voice/queue-poll', 'POST', array_merge([
            'CallSid' => self::CALL_ID,
            'session_data' => json_encode($sessionData),
        ], $extra));
    }

    private function sessionData(): array
    {
        return [
            'call_queue_id' => $this->queue->id,
            'call_id' => self::CALL_ID,
            'organization_id' => $this->organization->id,
            'callback_type' => 'queue_poll',
        ];
    }

    public function test_enqueue_creates_queue_call_and_returns_hold_cxml(): void
    {
        Http::fake(['http://acd-worker:8084/*' => Http::response(['position' => 1], 200)]);

        $strategy = app(QueueRoutingStrategy::class);
        $response = $strategy->route($this->voiceRequest(), new DidNumber, [
            'call_queue' => $this->queue,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Say>', $content); // no MOH configured
        $this->assertStringContainsString('queue-poll', $content);

        $this->assertDatabaseHas('queue_calls', [
            'call_queue_id' => $this->queue->id,
            'call_id' => self::CALL_ID,
            'organization_id' => $this->organization->id,
            'disposition' => null,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/queue/enqueue')
            && $request['callId'] === self::CALL_ID);
    }

    public function test_poll_endpoint_accepts_in_progress_status_via_http(): void
    {
        // Regression: Cloudonix sends CallStatus "in-progress" on poll redirects.
        // A validation failure must not produce a 302 redirect (which drops the call).
        $this->queue->update(['moh_recording_id' => null]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 1], 200)]);

        $token = (string) Str::random(32);
        CloudonixSettings::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organization->id)
            ->first()
            ?->update([
                'domain_name' => 'test.example.com',
                'domain_requests_api_key' => $token,
            ]);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        $sessionData = urlencode(json_encode($this->sessionData()));

        $response = $this->postJson("/api/callbacks/voice/queue-poll?session_data={$sessionData}", [
            'CallSid' => self::CALL_ID,
            'CallStatus' => 'in-progress',
            'From' => '10000',
            'To' => '20001',
            'Domain' => 'test.example.com',
            // Cloudonix sends its own session profile in SessionData; the queue
            // context must still be resolved from session_data.
            'SessionData' => [
                'id' => 32018519,
                'token' => self::CALL_ID,
                'status' => 'CONNECTED',
                'callIds' => ['EB9-r_g3dkai9QG1xmLSqw..'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk();
        $this->assertStringContainsString('<Response>', (string) $response->getContent());
        $this->assertStringNotContainsString('Redirecting to', (string) $response->getContent());
    }

    public function test_poll_wait_returns_hold_cxml_again(): void
    {
        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now(),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 1], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Say>', $content);
        $this->assertStringContainsString('<Pause length="5"/>', $content);
        $this->assertStringContainsString('queue-poll', $content);
    }

    public function test_poll_dial_returns_direct_extension_dial_without_forward_chaining(): void
    {
        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'action' => 'dial',
            'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
        ], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1001', $content);
        $this->assertStringContainsString((string) $this->queue->agent_ring_timeout, $content);
        // HARD RULE: the target is the raw extension number — never a forward destination.
        $this->assertStringNotContainsString('forward', strtolower($content));
    }

    public function test_agent_dial_abides_by_inbound_recording_policy(): void
    {
        // Policy: calls entering a queue are inbound calls and must be
        // recorded per the organization's inbound recording mode.
        CloudonixSettings::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organization->id)
            ->first()
            ?->update(['call_recording_mode' => 'all']);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'action' => 'dial',
            'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
        ], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('record="record-from-answer"', $content);
        $this->assertStringContainsString('recordingStatusCallback=', $content);
    }

    public function test_poll_skips_presence_busy_agents(): void
    {
        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Redis::setex("acd:presence:{$this->organization->id}:1001", 3600, 'active');

        Http::fake(['http://acd-worker:8084/*' => Http::response([
            'action' => 'dial',
            'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
        ], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('<Dial', $content);
        $this->assertStringContainsString('<Say>', $content);
    }

    public function test_session_deletion_clears_presence_whatever_its_final_status(): void
    {
        // Regression (customer session ed4819f9...): Cloudonix sends the
        // session deletion with the final status ('connected'), which kept the
        // destination presence-busy for the full 6h TTL - agents rung in their
        // own sessions would never be offered again.
        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);
        $lifecycle = app(QueueCallLifecycleService::class);
        $presence = app(ExtensionPresenceTracker::class);

        $lifecycle->handleSessionUpdate(SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'destination' => '1001',
            'status' => 'connected',
            'action' => 'updated',
        ]));
        $this->assertTrue($presence->isBusy($this->organization->id, '1001'));

        $lifecycle->handleSessionUpdate(SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'destination' => '1001',
            'status' => 'connected',
            'action' => 'deleted',
        ]));
        $this->assertFalse($presence->isBusy($this->organization->id, '1001'));
    }

    public function test_caller_overflows_on_own_max_wait_even_when_not_at_head(): void
    {
        // Regression: a dead/abandoned caller rotting at the head of the worker
        // queue is never polled, so the worker only ever answers "wait" for
        // callers behind it. Max wait must be enforced per caller from
        // queue_calls.entered_at, not per head-of-queue in the worker.
        $fallbackIvr = IvrMenu::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->queue->update([
            'max_wait_seconds' => 30,
            'fallback_action' => 'ivr_menu',
            'fallback_ivr_menu_id' => $fallbackIvr->id,
        ]);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now()->subSeconds(90),
        ]);

        // Worker says "wait" (caller is stuck behind a stale head entry).
        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 2], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $this->assertSame(QueueCallDisposition::OVERFLOW, QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_id', self::CALL_ID)->first()->disposition);

        // Fallback routes into the IVR menu (Gather-based CXML, not a hold loop),
        // preceded by the apology message in the queue's language.
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('queue-poll', $content);
        $this->assertStringContainsString('no one is available to take your call', $content);
        $this->assertLessThan(
            strpos($content, '</Say>'),
            strpos($content, 'no one is available'),
            'Apology must be spoken in the first Say verb, before the fallback routing'
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'overflow');
    }

    public function test_position_announced_on_first_poll_regardless_of_interval(): void
    {
        Redis::del('acd:announce:'.self::CALL_ID);

        // Interval (60s) exceeds max wait (30s): without announce-on-entry the
        // caller would overflow without ever hearing their position.
        $this->queue->update([
            'announce_position' => true,
            'announce_position_timeout' => 60,
            'announce_position_language' => 'en-US',
            'max_wait_seconds' => 30,
        ]);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now()->subSeconds(2),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 1], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $this->assertStringContainsString('caller number 1', (string) $response->getContent());
    }

    public function test_poll_during_in_flight_offer_reserves_same_dial_not_a_new_offer(): void
    {
        // Stale hold document (pre-switch) fires a poll while the proactive
        // <Dial> is still ringing: the poll must NOT re-offer via the worker.
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('<Say>', $content);

        // The worker must not be asked for a new offer while one is in flight.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/queue/poll'));
    }

    public function test_poll_after_bridge_returns_same_dial_not_hold(): void
    {
        // The stale hold document's Redirect fires after the agent answered:
        // responding with hold CXML would tear down the live bridge (the
        // customer-visible congestion). The poll must re-serve the same dial.
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'answered_at' => now(),
            'agent_user_id' => $this->agent->id,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1001', $content);
        $this->assertStringNotContainsString('<Say>', $content);
    }

    public function test_dial_failure_reoffers_next_agent_immediately(): void
    {
        // Regression: a failed agent dial returned the hold CXML, so the caller
        // waited out the full MOH/pause cycle before the next offer even when
        // another agent was available. The failure callback must re-offer now.
        Redis::del('acd:dial:'.self::CALL_ID);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake([
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => '999', 'extensionNumber' => '1099']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $sessionData = array_merge($this->sessionData(), ['callback_type' => 'queue_dial_callback']);
        $response = app(QueueDialCallbackController::class)->handle(
            $this->callbackRequest($sessionData, ['CallStatus' => 'no-answer'])
        );

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('1099', $content);
        $this->assertStringNotContainsString('<Say>', $content);

        // The worker is re-polled for the next offer.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/queue/poll'));
    }

    public function test_dial_failure_without_agents_falls_back_to_hold(): void
    {
        Redis::del('acd:dial:'.self::CALL_ID);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake([
            'http://acd-worker:8084/queue/poll' => Http::response(['action' => 'wait', 'position' => 1], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $sessionData = array_merge($this->sessionData(), ['callback_type' => 'queue_dial_callback']);
        $response = app(QueueDialCallbackController::class)->handle(
            $this->callbackRequest($sessionData, ['CallStatus' => 'busy'])
        );

        $this->assertStringContainsString('<Say>', (string) $response->getContent());
    }

    public function test_post_bridge_failure_callback_terminates_call_not_redial(): void
    {
        // Regression (customer): ring-all dial to [unregistered 9000, 9001];
        // 9001 answers, then hangs up. The dial action callback reports the
        // LOSING leg's status (unregistered -> 'failed') instead of the
        // completed bridge. Treating that as a dial failure caused an
        // immediate re-offer loop (redialing 9001, origin never terminates,
        // congestion) - the callback must recognize the bridge happened and
        // end the call cleanly.
        Redis::del('acd:dial:'.self::CALL_ID);
        Redis::del('acd:dialresult:'.self::CALL_ID);

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'answered_at' => now()->subSeconds(25),
            'agent_user_id' => $this->agent->id,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake([
            'http://acd-worker:8084/queue/poll' => Http::response([
                'action' => 'dial',
                'agents' => [['userId' => (string) $this->agent->id, 'extensionNumber' => '1001']],
            ], 200),
            'http://acd-worker:8084/*' => Http::response([], 204),
        ]);

        $sessionData = array_merge($this->sessionData(), ['callback_type' => 'queue_dial_callback']);
        $response = app(QueueDialCallbackController::class)->handle(
            $this->callbackRequest($sessionData, ['CallStatus' => 'failed'])
        );

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Hangup/>', $content);
        $this->assertStringNotContainsString('<Dial', $content, 'must not redial after the bridge ended');

        // No re-offer via the worker, and the outcome is recorded as a bridge.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/queue/poll'));
        $this->assertSame('answered', Redis::get('acd:dialresult:'.self::CALL_ID));

        Redis::del('acd:dial:'.self::CALL_ID);
        Redis::del('acd:dialresult:'.self::CALL_ID);
    }

    public function test_poll_announces_position_on_interval(): void
    {
        Redis::del('acd:announce:'.self::CALL_ID);

        $this->queue->update([
            'announce_position' => true,
            'announce_position_timeout' => 30,
            'announce_position_language' => 'en-US',
        ]);

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now()->subSeconds(45),
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'wait', 'position' => 2], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));
        $content = (string) $response->getContent();
        $this->assertStringContainsString('caller number 2', $content);
        $this->assertStringContainsString('language="en-US"', $content);

        // Second poll within the interval: no announcement.
        $response2 = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));
        $this->assertStringNotContainsString('caller number', (string) $response2->getContent());
    }

    public function test_poll_overflow_marks_call_and_runs_fallback(): void
    {
        $fallbackExtension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '2000',
            'type' => ExtensionType::USER,
        ]);
        $this->queue->update([
            'fallback_action' => 'extension',
            'fallback_extension_id' => $fallbackExtension->id,
        ]);

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response(['action' => 'overflow'], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(QueueCallDisposition::OVERFLOW, $queueCall->refresh()->disposition);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'overflow');
    }

    public function test_dial_callback_failed_returns_caller_to_hold(): void
    {
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $sessionData = array_merge($this->sessionData(), ['callback_type' => 'queue_dial_callback']);
        $response = app(QueueDialCallbackController::class)->handle(
            $this->callbackRequest($sessionData, ['CallStatus' => 'no-answer'])
        );

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Say>', $content);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'dial_failed'
            && $request['agentUserId'] === (string) $this->agent->id);
    }

    public function test_session_update_answer_marks_queue_call(): void
    {
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $sessionUpdate = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'call_ids' => ['sip-call-id-1'], // SIP ids differ from the voice CallSid
            'status' => 'answered',
        ]);

        app(QueueCallLifecycleService::class)->handleSessionUpdate($sessionUpdate);

        $queueCall->refresh();
        $this->assertNull($queueCall->disposition);
        $this->assertNotNull($queueCall->answered_at);
        $this->assertSame($this->agent->id, $queueCall->agent_user_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'answered');
    }

    public function test_connected_status_marks_bridge_and_attributes_offered_agent_not_caller(): void
    {
        // Regression (customer logs): the agent bridge is reported as
        // status CONNECTED (uppercase, with callAnswerTime) - not 'answer'.
        // Without accepting it, answered_at stays null and every downstream
        // guard misfires.
        // Regression (customer session ed4819f9...): outgoingSubscriberId is the
        // CALLER's subscriber - present from the very first route request,
        // before any agent is dialed. Attribution must never follow it.
        Redis::del('acd:dial:'.self::CALL_ID);
        Redis::del('acd:dialresult:'.self::CALL_ID);

        $callerUser = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $callerUser->id,
            'extension_number' => '9000',
            'cloudonix_subscriber_id' => 250707,
        ]);

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $bridgeUpdate = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'status' => 'connected',
            'outgoing_subscriber_id' => 250707,
        ]);

        app(QueueCallLifecycleService::class)->handleSessionUpdate($bridgeUpdate);

        $queueCall->refresh();
        $this->assertNotNull($queueCall->answered_at);
        $this->assertSame($this->agent->id, $queueCall->agent_user_id, 'single-target offer attributes the offered agent');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'answered'
            && $request['agentUserId'] === (string) $this->agent->id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/events')
            && ($request['agentUserId'] ?? null) === (string) $callerUser->id);
    }

    public function test_ring_all_bridge_credits_no_agent_when_winner_unknown(): void
    {
        // A multi-target <Dial> carries no reliable winning leg. Crediting the
        // first-offered agent (or the caller) marked the wrong user BUSY /
        // WRAP_UP in the worker while the real answerer stayed AVAILABLE.
        $second = $this->createAgent('1002');

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, [$this->agent->id, $second->id]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $bridgeUpdate = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'status' => 'connected',
        ]);

        app(QueueCallLifecycleService::class)->handleSessionUpdate($bridgeUpdate);

        $queueCall->refresh();
        $this->assertNotNull($queueCall->answered_at, 'the bridge is still detected');
        $this->assertNull($queueCall->agent_user_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'answered'
            && ! isset($request['agentUserId']));
    }

    public function test_ring_all_cdr_finalizes_without_crediting_an_agent(): void
    {
        $second = $this->createAgent('1002');

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now()->subMinutes(2),
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, [$this->agent->id, $second->id]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        app(QueueCallLifecycleService::class)->recordDialResult(self::CALL_ID, 'completed');
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => [
                'token' => self::CALL_ID,
                'callAnswerTime' => now()->subMinute()->getTimestampMs(),
                'callEndTime' => now()->getTimestampMs(),
            ],
        ]);

        $queueCall->refresh();
        $this->assertSame(QueueCallDisposition::ANSWERED, $queueCall->disposition);
        $this->assertNull($queueCall->agent_user_id);

        // No agent is pushed into wrap-up on a guess.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'ended'
            && ! isset($request['agentUserId']));
    }

    public function test_ring_all_dial_failure_releases_no_agent(): void
    {
        // Ring-all never marks its agents BUSY, so a failed offer has nobody
        // to release (an unconditional dial_failed would also resurrect a
        // LOGGED_OUT agent in the worker).
        $second = $this->createAgent('1002');
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, [$this->agent->id, $second->id]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        app(QueueCallLifecycleService::class)->handleDialFailed($this->queue, self::CALL_ID);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'dial_failed');
    }

    public function test_poll_during_in_flight_ring_all_offer_reserves_every_target(): void
    {
        // Regression (Scenario 3, "only one rings"): the stale-poll guard
        // re-served the in-flight ring_all <Dial> to the first-offered agent
        // only, replacing the parallel offer with a single-target one.
        $second = $this->createAgent('1002');

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, [$this->agent->id, $second->id]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Number>1001</Number>', $content);
        $this->assertStringContainsString('<Number>1002</Number>', $content);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/queue/poll'));

        // The re-serve keeps the original offer set intact.
        $this->assertSame(
            [$this->agent->id, $second->id],
            app(QueueCallLifecycleService::class)->dialOfferAgentIds(self::CALL_ID)
        );
    }

    public function test_poll_after_ring_all_bridge_keeps_first_offered_reserve(): void
    {
        // Deliberately unchanged (pending conference-mediated offers): after a
        // ring_all bridge the winner is unknown, and a non-<Dial> response tears
        // the live bridge down - the first-offered agent is re-served.
        $second = $this->createAgent('1002');

        QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'answered_at' => now(),
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, [$this->agent->id, $second->id]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 200)]);

        $response = app(QueuePollController::class)->handle($this->callbackRequest($this->sessionData()));

        $content = (string) $response->getContent();
        $this->assertStringContainsString('<Number>1001</Number>', $content);
        $this->assertStringNotContainsString('1002', $content);
    }

    private function createAgent(string $extensionNumber): User
    {
        $agent = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $agent->id,
            'extension_number' => $extensionNumber,
            'type' => ExtensionType::USER,
        ]);
        CallQueueAgent::factory()->create([
            'organization_id' => $this->organization->id,
            'call_queue_id' => $this->queue->id,
            'user_id' => $agent->id,
        ]);

        return $agent;
    }

    public function test_connected_status_without_dial_marker_is_platform_answer(): void
    {
        // 'connected' also fires at the initial platform answer (before any
        // offer) - without a dial marker it must NOT mark the bridge.
        Redis::del('acd:dial:'.self::CALL_ID);
        Redis::del('acd:dialresult:'.self::CALL_ID);

        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $platformAnswer = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'status' => 'connected',
        ]);

        app(QueueCallLifecycleService::class)->handleSessionUpdate($platformAnswer);

        $this->assertNull($queueCall->refresh()->answered_at);
    }

    public function test_session_update_webhook_normalizes_status_case(): void
    {
        // Cloudonix sends statuses in upper case (CONNECTED, ANSWERER...);
        // the webhook must not drop them for casing reasons.
        $owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        CloudonixSettings::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organization->id)
            ->first()
            ?->update(['domain_name' => 'test.example.com']);

        $settings = CloudonixSettings::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organization->id)
            ->first();

        $payload = [
            'id' => 987654321,
            'eventId' => 'evt-'.self::CALL_ID,
            'domainId' => 1764,
            'domain' => 'test.example.com',
            'subscriberId' => '248967',
            'callerId' => '9099',
            'destination' => '20001',
            'direction' => 'incoming',
            'status' => 'CONNECTED',
            'createdAt' => now()->subMinute()->toIso8601String(),
            'modifiedAt' => now()->toIso8601String(),
            'action' => 'none',
            'reason' => 'normal',
        ];

        $response = $this->postJson('/api/webhooks/cloudonix/session-update', $payload, [
            'Authorization' => 'Bearer '.$settings->domain_requests_api_key,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('session_updates', [
            'organization_id' => $this->organization->id,
            'status' => 'connected',
        ]);
    }

    public function test_initial_platform_answer_does_not_mark_answered(): void
    {
        Redis::del('acd:dial:'.self::CALL_ID);

        // The session answers when the caller connects to the voice platform
        // (before any dial offer). Only the agent-bridge answer may anchor
        // waiting/handling times.
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $initialAnswer = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'status' => 'answer',
        ]);

        app(QueueCallLifecycleService::class)->handleSessionUpdate($initialAnswer);

        $this->assertNull($queueCall->refresh()->answered_at);
    }

    public function test_spurious_teardown_answer_is_demoted_to_abandoned(): void
    {
        Redis::del('acd:dial:'.self::CALL_ID);
        Redis::del('acd:dialresult:'.self::CALL_ID);
        Redis::del('acd:bridge:'.self::CALL_ID);

        // Regression: cloudonix emits an 'answer' status update at call
        // teardown while a dial offer is pending. Without the dial callback
        // result as the authority, the abandoned call was finalized as
        // 'answered'.
        $enteredAt = now()->subMinutes(2);
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => $enteredAt,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $teardownAnswer = SessionUpdate::factory()->create([
            'organization_id' => $this->organization->id,
            'session_token' => self::CALL_ID,
            'status' => 'answer',
            'session_modified_at' => now()->subMinute(),
        ]);
        app(QueueCallLifecycleService::class)->handleSessionUpdate($teardownAnswer);

        $this->assertNotNull($queueCall->refresh()->answered_at, 'spurious update marks answered pending confirmation');

        // The dial callback reports the offer outcome: no bridge.
        app(QueueCallLifecycleService::class)->recordDialResult(self::CALL_ID, 'no-answer');

        $endMs = now()->getTimestampMs();
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => [
                'token' => self::CALL_ID,
                'callEndTime' => $endMs,
            ],
        ]);

        $queueCall->refresh();
        $this->assertSame(QueueCallDisposition::ABANDONED, $queueCall->disposition);
        $this->assertNull($queueCall->answered_at);
        $this->assertNull($queueCall->agent_user_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'abandoned');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'dial_failed'
            && $request['agentUserId'] === (string) $this->agent->id);
    }

    public function test_cdr_without_answer_marks_abandoned(): void
    {
        $enteredAt = now()->subMinutes(2);
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => $enteredAt,
        ]);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $endMs = $enteredAt->copy()->addMinutes(1)->getTimestampMs();
        app(QueueCallLifecycleService::class)->recordDialResult(self::CALL_ID, 'canceled');
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => [
                'token' => self::CALL_ID,
                'callEndTime' => $endMs,
            ],
        ]);

        $queueCall->refresh();
        $this->assertSame(QueueCallDisposition::ABANDONED, $queueCall->disposition);
        $this->assertSame(60, $queueCall->waiting_seconds);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'abandoned');
    }

    public function test_cdr_with_answer_finalizes_handling_time(): void
    {
        $enteredAt = now()->subMinutes(3);
        $queueCall = QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => $enteredAt,
        ]);
        app(QueueCallLifecycleService::class)->markDial($this->queue->id, self::CALL_ID, $this->agent->id);

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);

        $answerMs = $enteredAt->copy()->addMinutes(1)->getTimestampMs();
        $endMs = $enteredAt->copy()->addMinutes(3)->getTimestampMs();
        app(QueueCallLifecycleService::class)->recordDialResult(self::CALL_ID, 'completed');
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => 'sip-call-id-1',
            'session' => [
                'token' => self::CALL_ID,
                'callAnswerTime' => $answerMs,
                'callEndTime' => $endMs,
            ],
        ]);

        $queueCall->refresh();
        $this->assertSame(QueueCallDisposition::ANSWERED, $queueCall->disposition);
        $this->assertSame(60, $queueCall->waiting_seconds);
        $this->assertSame(120, $queueCall->handling_seconds);
        $this->assertSame($this->agent->id, $queueCall->agent_user_id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'ended'
            && $request['talkSeconds'] === 120);
    }
}
