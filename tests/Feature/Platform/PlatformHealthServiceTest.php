<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformHealthCheck;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('recordings');
        Storage::fake('cdr_archive');
        Queue::fake();
    }

    /** @return array<string, array{status: string, detail: string, latency_ms: int|null}> */
    private function probe(array $fakes = []): array
    {
        Http::fake($fakes + ['*' => Http::response('ok', 200)]);

        return app(PlatformHealthService::class)->run();
    }

    public function test_all_components_are_checked_and_healthy_stack_is_ok(): void
    {
        Cache::put(PlatformHealthService::HEARTBEAT_KEY, time());

        $results = $this->probe();

        $this->assertSame(array_keys(PlatformHealthService::COMPONENTS), array_keys($results));
        foreach ($results as $component => $r) {
            $this->assertSame('ok', $r['status'], "$component: {$r['detail']}");
        }
        $this->assertStringContainsString('MySQL', $results['mysql']['detail']);
        $this->assertStringContainsString('PONG', $results['redis']['detail']);
        $this->assertStringContainsString('reachable', $results['cloudonix']['detail']);
    }

    public function test_worker_http_error_is_down_and_connection_failure_is_down(): void
    {
        $results = $this->probe([
            'acd-worker:8085/*' => Http::response('boom', 500),
            'amd-worker:8083/*' => fn () => throw new ConnectionException('refused'),
        ]);

        $this->assertSame('down', $results['acd_worker']['status']);
        $this->assertSame('HTTP 500', $results['acd_worker']['detail']);
        $this->assertSame('down', $results['amd_worker']['status']);
        $this->assertSame('ok', $results['dialer_worker']['status']);
    }

    public function test_optional_components_not_running_are_unknown_not_down(): void
    {
        $results = $this->probe([
            'loki:3100/*' => fn () => throw new ConnectionException('no such host'),
            'grafana:3000/*' => fn () => throw new ConnectionException('no such host'),
        ]);

        $this->assertSame('unknown', $results['loki']['status']);
        $this->assertNull($results['loki']['latency_ms']);
        $this->assertSame('unknown', $results['grafana']['status']);
    }

    public function test_optional_component_answering_with_error_is_down(): void
    {
        $results = $this->probe(['loki:3100/*' => Http::response('', 503)]);

        $this->assertSame('down', $results['loki']['status']);
    }

    public function test_cloudonix_any_http_response_is_reachable_and_no_credentials_are_sent(): void
    {
        config(['services.cloudonix.api_base_url' => 'https://api.cloudonix.io']);
        $results = $this->probe(['api.cloudonix.io*' => Http::response('', 404)]);

        $this->assertSame('ok', $results['cloudonix']['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.cloudonix.io')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_cloudonix_connection_failure_is_down(): void
    {
        config(['services.cloudonix.api_base_url' => 'https://api.cloudonix.io']);
        $down = $this->probe(['api.cloudonix.io*' => fn () => throw new ConnectionException('x')]);
        $this->assertSame('down', $down['cloudonix']['status']);
    }

    public function test_scheduler_age_thresholds(): void
    {
        $this->assertSame('unknown', $this->probe()['scheduler']['status']);

        foreach ([[30, 'ok'], [59, 'ok'], [60, 'degraded'], [179, 'degraded'], [180, 'down'], [11 * 60, 'down']] as [$age, $expected]) {
            Cache::put(PlatformHealthService::HEARTBEAT_KEY, time() - $age);
            $this->assertSame($expected, $this->probe()['scheduler']['status'], "age {$age}s");
        }
    }

    public function test_queue_is_degraded_over_failed_threshold(): void
    {
        config(['platform_status.queue.failed_degraded' => 2]);
        foreach ([1, 2] as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => "u$i", 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
            ]);
        }

        $r = $this->probe()['queue'];

        $this->assertSame('degraded', $r['status']);
        $this->assertStringContainsString('2 failed', $r['detail']);
    }

    public function test_unreachable_bucket_makes_seaweedfs_down(): void
    {
        $service = \Mockery::mock(PlatformHealthService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('bucketReachable')->with('recordings')->andReturn(true);
        $service->shouldReceive('bucketReachable')->with('cdr_archive')->andReturn(false);
        Http::fake(['*' => Http::response('ok')]);

        $r = $service->run()['seaweedfs'];

        $this->assertSame('down', $r['status']);
        $this->assertStringContainsString('cdr_archive bucket unreachable', $r['detail']);
    }

    public function test_run_and_store_writes_one_row_per_component(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        app(PlatformHealthService::class)->runAndStore();

        $this->assertSame(count(PlatformHealthService::COMPONENTS), PlatformHealthCheck::count());
        $this->assertSame(1, PlatformHealthCheck::where('component', 'redis')->count());
    }
}
