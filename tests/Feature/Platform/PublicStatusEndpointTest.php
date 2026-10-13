<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformHealthCheck;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function check(string $component, string $status, \DateTimeInterface $at): void
    {
        PlatformHealthCheck::create([
            'component' => $component, 'status' => $status, 'latency_ms' => 12,
            'detail' => ['message' => 'MySQL 8.0.1 at db-internal-host ms'], 'checked_at' => $at,
        ]);
    }

    private function seedAllOk(): void
    {
        $t = now()->subMinutes(2);
        foreach (PlatformHealthService::COMPONENTS as $component => $_) {
            $this->check($component, 'ok', $t);
        }
    }

    public function test_is_public_and_returns_six_groups(): void
    {
        $this->seedAllOk();

        $data = $this->getJson('/api/v1/public/status')->assertOk()->json('data');

        $this->assertSame('ok', $data['status']);
        $this->assertCount(6, $data['groups']);
        $this->assertSame(
            ['web_portal_api', 'call_routing', 'call_queues', 'dialer_voicemail', 'recordings_storage', 'realtime_updates'],
            array_column($data['groups'], 'key'),
        );
        $this->assertNotNull($data['updated_at']);
    }

    public function test_group_status_is_worst_member_and_history_is_worst_per_bucket_within_6h(): void
    {
        $this->seedAllOk();
        $t = now()->startOfHour()->subHour();
        $this->check('mysql', 'down', $t->copy()->addMinute());
        $this->check('redis', 'degraded', $t->copy()->addMinutes(2)); // same bucket as the mysql down
        $this->check('app', 'ok', now()->subHours(8)); // outside 6h, ignored

        $data = $this->getJson('/api/v1/public/status')->assertOk()->json('data');
        $web = collect($data['groups'])->firstWhere('key', 'web_portal_api');

        // Latest rows are all ok; the down is only in history.
        $this->assertSame('ok', $web['status']);
        $this->assertContains('down', array_column($web['history'], 'status'));
        $this->assertNotContains('degraded', array_column(array_filter($web['history'], fn ($h) => $h['at'] === $t->copy()->toIso8601String()), 'status'));
        foreach ($web['history'] as $h) {
            $this->assertGreaterThanOrEqual(now()->subHours(6)->subMinutes(5)->timestamp, strtotime($h['at']));
        }
    }

    public function test_current_down_member_makes_group_and_overall_down_and_missing_member_is_unknown(): void
    {
        $this->check('soketi', 'down', now()->subMinute());
        $this->check('cloudonix', 'degraded', now()->subMinute());

        $data = $this->getJson('/api/v1/public/status')->assertOk()->json('data');
        $groups = collect($data['groups'])->keyBy('key');

        $this->assertSame('down', $groups['realtime_updates']['status']);
        $this->assertSame('degraded', $groups['call_routing']['status']); // app has no data (unknown) < degraded
        $this->assertSame('unknown', $groups['call_queues']['status']);
        $this->assertSame('down', $data['status']);
    }

    public function test_response_leaks_no_internal_details(): void
    {
        $this->seedAllOk();
        $this->check('mysql', 'down', now());

        $body = strtolower($this->getJson('/api/v1/public/status')->assertOk()->getContent());

        foreach (['mysql', 'redis', 'seaweedfs', 'soketi', 'cloudonix', 'acd_worker', 'amd_worker', 'dialer_worker', 'scheduler',
            'loki', 'grafana', 'mcp', 'detail', 'latency', 'version', 'host', 'message', 'db-internal'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "public status must not contain '$needle'");
        }
        $this->assertStringNotContainsString('"ms"', $body);
    }

    public function test_response_is_cached_for_30_seconds(): void
    {
        $this->seedAllOk();
        $this->getJson('/api/v1/public/status')->assertOk();
        $this->assertTrue(Cache::has('public:status'));
        $this->check('soketi', 'down', now());

        $this->assertSame('ok', $this->getJson('/api/v1/public/status')->json('data.status'));
    }

    public function test_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/public/status')->assertOk();
        }
        $this->getJson('/api/v1/public/status')->assertStatus(429);
    }
}
