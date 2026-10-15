<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallQueue;

use App\Jobs\SendAcdEventJob;
use App\Services\CallQueue\AcdWorkerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AcdWorkerClientEventRetryTest extends TestCase
{
    public function test_a_failed_event_is_queued_for_replay(): void
    {
        Queue::fake();
        Http::fake(fn () => throw new ConnectionException('worker restarting'));

        (new AcdWorkerClient)->event(1, 2, 'call-1', 'ended', 7, 30);

        Queue::assertPushed(SendAcdEventJob::class, fn (SendAcdEventJob $job) => $job->payload['type'] === 'ended' && $job->payload['callId'] === 'call-1');
    }

    public function test_the_replay_carries_the_same_event_id_as_the_inline_attempt(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 503)]);

        (new AcdWorkerClient)->event(1, 2, 'call-1', 'ended', 7, 30);

        $sentId = null;
        Http::assertSent(function ($request) use (&$sentId) {
            $sentId = $request['eventId'] ?? null;

            return true;
        });
        $this->assertNotEmpty($sentId);
        Queue::assertPushed(SendAcdEventJob::class, fn (SendAcdEventJob $job) => $job->payload['eventId'] === $sentId);
    }

    public function test_each_event_gets_its_own_id(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $client = new AcdWorkerClient;
        $client->event(1, 2, 'call-1', 'dial_failed', 7);
        $client->event(1, 2, 'call-1', 'dial_failed', 7);

        $ids = Http::recorded()->map(fn ($pair) => $pair[0]['eventId'])->all();
        $this->assertCount(2, array_unique($ids));
    }

    public function test_an_accepted_event_is_not_queued(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new AcdWorkerClient)->event(1, 2, 'call-1', 'ended');

        Queue::assertNothingPushed();
    }

    public function test_the_job_fails_so_it_retries_while_the_worker_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('still down'));
        $job = new SendAcdEventJob(['type' => 'ended', 'callId' => 'call-1']);

        $this->expectException(\RuntimeException::class);
        $job->handle(new AcdWorkerClient);
    }

    public function test_the_job_succeeds_once_the_worker_is_back(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new SendAcdEventJob(['type' => 'ended', 'callId' => 'call-1']))->handle(new AcdWorkerClient);

        Http::assertSentCount(1);
    }

    public function test_the_job_has_a_retry_policy(): void
    {
        $job = new SendAcdEventJob([]);

        $this->assertSame(5, $job->tries);
        $this->assertSame([5, 15, 60, 300], $job->backoff);
    }
}
