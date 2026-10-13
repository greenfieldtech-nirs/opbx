<?php

declare(strict_types=1);

namespace Tests\Feature\CallNotifications;

use App\Jobs\DispatchCallNotificationJob;
use App\Models\CallNotificationLog;
use App\Models\CallNotificationsSettings;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Services\CallNotifications\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class CallNotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'notify.cloudonix.net';

    private const KEY = 'notify-requests-api-key-1234567890';

    private Organization $org;

    private CallNotificationsSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $this->org->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::KEY,
        ]);
        $this->settings = CallNotificationsSettings::factory()->create([
            'organization_id' => $this->org->id,
            'retry_attempts' => 2,
            'request_timeout_seconds' => 10,
        ]);
    }

    public function test_session_update_queues_the_job_and_returns_without_calling_the_customer(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $this->postJson('/api/webhooks/cloudonix/session-update', [
            'id' => 880001, 'token' => 'tok', 'eventId' => 'evt-n1', 'domainId' => 1, 'domain' => self::DOMAIN,
            'subscriberId' => 1, 'callerId' => '+15550001111', 'destination' => '+15550002222',
            'direction' => 'incoming', 'status' => 'ringing', 'createdAt' => '2026-10-11T10:00:00Z',
            'modifiedAt' => '2026-10-11T10:00:01Z', 'action' => 'dial', 'reason' => 'ok',
        ], ['Authorization' => 'Bearer '.self::KEY])->assertOk();

        Queue::assertPushed(DispatchCallNotificationJob::class);
        Http::assertNothingSent();
    }

    public function test_job_uses_the_retry_config(): void
    {
        $job = new DispatchCallNotificationJob($this->settings, [], 'e', 't');

        $this->assertSame(3, $job->tries);
        $this->assertSame(15, $job->timeout);
        $this->assertSame([60, 120], $job->backoff);
    }

    public function test_backoff_follows_the_configured_base_and_doubles(): void
    {
        $this->settings->update(['retry_attempts' => 3, 'retry_backoff_seconds' => 30]);

        $job = new DispatchCallNotificationJob($this->settings->fresh(), [], 'e', 't');

        $this->assertSame([30, 60, 120], $job->backoff);
    }

    public function test_a_failing_endpoint_is_attempted_once_per_run_and_released(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Redis::shouldReceive('incr')->andReturn(1);
        Redis::shouldReceive('expire')->andReturn(true);

        $job = new DispatchCallNotificationJob($this->settings, ['event_type' => 'call.status_update'], 'evt-r1', 'tok');
        $job->withFakeQueueInteractions();
        $job->handle(app(WebhookDispatcher::class));

        Http::assertSentCount(1);
        $job->assertReleased(60);
        $this->assertSame(1, CallNotificationLog::withoutGlobalScopes()->where('event_id', 'evt-r1')->count());
    }

    public function test_a_client_error_is_not_released(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        Redis::shouldReceive('incr')->andReturn(1);
        Redis::shouldReceive('expire')->andReturn(true);

        $job = new DispatchCallNotificationJob($this->settings, ['event_type' => 'call.status_update'], 'evt-r2', 'tok');
        $job->withFakeQueueInteractions();
        $job->handle(app(WebhookDispatcher::class));

        $job->assertNotReleased();
    }

    public function test_rate_limiter_counts_atomically_and_sets_ttl_once(): void
    {
        Redis::shouldReceive('incr')->once()->andReturn(1);
        Redis::shouldReceive('expire')->once()->with('call_notifications:rate_limit:'.$this->org->id, 60)->andReturn(true);
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertTrue(app(WebhookDispatcher::class)->dispatch($this->settings, ['event_type' => 'x'], 'evt-l1', 'tok'));
    }
}
