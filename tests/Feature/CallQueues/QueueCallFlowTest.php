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
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
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
        \App\Models\CallQueueAgent::factory()->create([
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
        $response = $strategy->route($this->voiceRequest(), new \App\Models\DidNumber, [
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

        $token = (string) \Illuminate\Support\Str::random(32);
        CloudonixSettings::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
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

    public function test_caller_overflows_on_own_max_wait_even_when_not_at_head(): void
    {
        // Regression: a dead/abandoned caller rotting at the head of the worker
        // queue is never polled, so the worker only ever answers "wait" for
        // callers behind it. Max wait must be enforced per caller from
        // queue_calls.entered_at, not per head-of-queue in the worker.
        $fallbackIvr = \App\Models\IvrMenu::factory()->create([
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

        $this->assertSame(QueueCallDisposition::OVERFLOW, QueueCall::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('call_id', self::CALL_ID)->first()->disposition);

        // Fallback routes into the IVR menu (Gather-based CXML, not a hold loop).
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('queue-poll', $content);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/events')
            && $request['type'] === 'overflow');
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
            'call_ids' => [self::CALL_ID],
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
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => self::CALL_ID,
            'session' => ['callEndTime' => $endMs],
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
        app(QueueCallLifecycleService::class)->handleCdr($this->organization->id, [
            'call_id' => self::CALL_ID,
            'session' => [
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
