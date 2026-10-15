<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Enums\QueueCallDisposition;
use App\Jobs\ReconcileQueueCallDispositionJob;
use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Services\CallQueue\QueueCallLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Claim-first queue call transitions (Task 2.7): a duplicate or racing
 * finalize/answer/overflow changes the row once and emits one ACD event.
 */
class QueueCallFinalizeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const CALL_ID = 'call-finalize-idem-1';

    private Organization $organization;

    private CallQueue $queue;

    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Redis::keys('acd:*') as $key) {
            Redis::del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create(['organization_id' => $this->organization->id]);
        $this->queue = CallQueue::factory()->create(['organization_id' => $this->organization->id]);

        $this->agentId = User::factory()->create(['organization_id' => $this->organization->id])->id;

        Http::fake(['http://acd-worker:8084/*' => Http::response([], 204)]);
    }

    private function queueCall(): QueueCall
    {
        return QueueCall::factory()->create([
            'call_queue_id' => $this->queue->id,
            'organization_id' => $this->organization->id,
            'call_id' => self::CALL_ID,
            'entered_at' => now()->subMinutes(3),
        ]);
    }

    private function eventCount(string $type): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/events') && $request['type'] === $type)->count();
    }

    private function cdr(): array
    {
        return ['call_id' => 'sip-1', 'session' => [
            'token' => self::CALL_ID,
            'callAnswerTime' => now()->subMinute()->getTimestampMs(),
            'callEndTime' => now()->getTimestampMs(),
        ]];
    }

    public function test_double_finalize_emits_one_ended_event(): void
    {
        $this->queueCall();
        $lifecycle = app(QueueCallLifecycleService::class);
        $lifecycle->markDial($this->queue->id, self::CALL_ID, $this->agentId);
        $lifecycle->recordDialResult(self::CALL_ID, 'completed');

        $lifecycle->handleCdr($this->organization->id, $this->cdr());
        $lifecycle->finalizeFromCdr($this->organization->id, $this->cdr());

        $this->assertSame(1, $this->eventCount('ended'));
    }

    public function test_finalize_loses_to_a_concurrent_overflow_claim(): void
    {
        $stale = $this->queueCall();
        $lifecycle = app(QueueCallLifecycleService::class);

        // Another worker already overflowed the row; this process still holds the pending model.
        QueueCall::withoutGlobalScopes()->whereKey($stale->id)->update(['disposition' => QueueCallDisposition::OVERFLOW->value]);
        $lifecycle->markOverflowed($stale);

        $this->assertSame(0, $this->eventCount('overflow'));
        $this->assertSame(QueueCallDisposition::OVERFLOW, QueueCall::withoutGlobalScopes()->find($stale->id)->disposition);
    }

    public function test_two_answers_emit_one_answered_event(): void
    {
        $this->queueCall();
        $lifecycle = app(QueueCallLifecycleService::class);
        $lifecycle->markDial($this->queue->id, self::CALL_ID, $this->agentId);

        foreach ([1, 2] as $n) {
            $lifecycle->handleSessionUpdate(SessionUpdate::factory()->create([
                'organization_id' => $this->organization->id,
                'session_token' => self::CALL_ID,
                'status' => 'connected',
                'session_modified_at' => now(),
            ]));
        }

        $this->assertSame(1, $this->eventCount('answered'));
    }

    public function test_conference_bridge_after_finalize_emits_nothing(): void
    {
        $stale = $this->queueCall();
        QueueCall::withoutGlobalScopes()->whereKey($stale->id)->update(['disposition' => QueueCallDisposition::ABANDONED->value]);

        app(QueueCallLifecycleService::class)->markConferenceBridge($stale, $this->agentId);

        $this->assertSame(0, $this->eventCount('answered'));
        $this->assertNull(QueueCall::withoutGlobalScopes()->find($stale->id)->answered_at);
    }

    public function test_reconcile_job_keeps_the_parked_cdr_when_finalize_throws_then_retries(): void
    {
        $queueCall = $this->queueCall();
        $lifecycle = app(QueueCallLifecycleService::class);
        $lifecycle->markDial($this->queue->id, self::CALL_ID, $this->agentId);
        $lifecycle->recordDialResult(self::CALL_ID, 'completed');
        Redis::setex('acd:cdr:'.self::CALL_ID, 3600, json_encode($this->cdr()));

        $failing = $this->createMock(QueueCallLifecycleService::class);
        $failing->method('finalizeFromCdr')->willThrowException(new \RuntimeException('boom'));

        $job = new ReconcileQueueCallDispositionJob($queueCall->id);

        try {
            $job->handle($failing);
            $this->fail('finalize failure must propagate so the job retries');
        } catch (\RuntimeException) {
        }

        $this->assertNotNull(Redis::get('acd:cdr:'.self::CALL_ID), 'parked CDR must survive a failed attempt');

        $job->handle($lifecycle);

        $this->assertSame(QueueCallDisposition::ANSWERED, QueueCall::withoutGlobalScopes()->find($queueCall->id)->disposition);
        $this->assertNull(Redis::get('acd:cdr:'.self::CALL_ID));
        $this->assertSame(1, $this->eventCount('ended'));
    }
}
