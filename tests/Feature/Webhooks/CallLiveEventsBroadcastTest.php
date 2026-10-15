<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\CallStatus;
use App\Events\CallAnswered;
use App\Events\CallEnded;
use App\Models\CallLog;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** session-update and CDR webhooks broadcast call.answered / call.ended once per call, for the Live Calls page. */
final class CallLiveEventsBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'live-events.cloudonix.net';

    private const KEY = 'live-events-requests-api-key-1234567890';

    private Organization $org;

    private CallLog $callLog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $this->org->id, 'domain_name' => self::DOMAIN, 'domain_requests_api_key' => self::KEY]);
        $this->callLog = CallLog::factory()->create(['organization_id' => $this->org->id, 'call_id' => 'session-tok', 'direction' => 'inbound']);
        Event::fake([CallAnswered::class, CallEnded::class]);
    }

    private function sessionUpdate(string $status, string $eventId, string $action = 'dial'): void
    {
        $this->postJson('/api/webhooks/cloudonix/session-update', [
            'id' => 777001, 'token' => 'session-tok', 'eventId' => $eventId, 'domainId' => 1, 'domain' => self::DOMAIN,
            'subscriberId' => 1, 'callerId' => '+15550001111', 'destination' => '+15550002222', 'direction' => 'incoming',
            'status' => $status, 'createdAt' => '2026-10-11T10:00:00Z', 'modifiedAt' => '2026-10-11T10:00:05Z', 'action' => $action, 'reason' => 'ok',
        ], ['Authorization' => 'Bearer '.self::KEY])->assertOk();
    }

    private function cdr(string $disposition = 'ANSWER'): void
    {
        $this->postJson('/api/webhooks/cloudonix/cdr', [
            'timestamp' => time(), 'domain' => self::DOMAIN, 'from' => '+15550001111', 'to' => '+15550002222',
            'disposition' => $disposition, 'duration' => 30, 'billsec' => 25, 'call_id' => 'cdr-call-1',
            'session' => ['token' => 'session-tok', 'id' => 777001],
        ], ['Authorization' => 'Bearer '.self::KEY])->assertOk();
    }

    public function test_connected_session_update_broadcasts_call_answered_once(): void
    {
        $this->sessionUpdate('CONNECTED', 'evt-1');
        $this->sessionUpdate('answer', 'evt-2');

        Event::assertDispatchedTimes(CallAnswered::class, 1);
        Event::assertDispatched(CallAnswered::class, function (CallAnswered $e): bool {
            $channel = $e->broadcastOn()[0];

            return $channel instanceof PresenceChannel
                && $channel->name === 'presence-org.'.$this->org->id
                && $e->broadcastAs() === 'call.answered'
                && $e->broadcastWith()['call_id'] === 'session-tok'
                && $e->broadcastWith()['status'] === 'answered'
                && $e->broadcastWith()['answered_at'] !== null;
        });
        $this->assertSame(CallStatus::ANSWERED, $this->callLog->fresh()->status);
    }

    public function test_cdr_broadcasts_call_ended_once_even_when_replayed(): void
    {
        $this->sessionUpdate('connected', 'evt-1');
        $this->cdr();
        $this->cdr();

        Event::assertDispatchedTimes(CallEnded::class, 1);
        Event::assertDispatched(CallEnded::class, fn (CallEnded $e): bool => $e->broadcastAs() === 'call.ended'
            && $e->broadcastWith()['call_id'] === 'session-tok'
            && $e->broadcastWith()['status'] === 'completed'
            && $e->broadcastWith()['duration'] === 30
            && $e->broadcastWith()['ended_at'] !== null);
    }

    public function test_terminal_session_update_ends_the_call_before_the_cdr(): void
    {
        $this->sessionUpdate('busy', 'evt-1');
        $this->cdr('BUSY');

        Event::assertDispatchedTimes(CallEnded::class, 1);
        Event::assertNotDispatched(CallAnswered::class);
        $this->assertSame(CallStatus::BUSY, $this->callLog->fresh()->status);
    }

    public function test_answer_after_the_call_ended_is_not_broadcast(): void
    {
        $this->sessionUpdate('completed', 'evt-1');
        $this->sessionUpdate('connected', 'evt-2');

        Event::assertDispatchedTimes(CallEnded::class, 1);
        Event::assertNotDispatched(CallAnswered::class);
        $this->assertSame(CallStatus::NO_ANSWER, $this->callLog->fresh()->status);
    }

    public function test_calls_without_a_call_log_broadcast_nothing(): void
    {
        $this->callLog->delete();
        $this->sessionUpdate('connected', 'evt-1');
        $this->cdr();

        Event::assertNotDispatched(CallAnswered::class);
        Event::assertNotDispatched(CallEnded::class);
    }
}
