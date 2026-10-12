<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\PlatformHealthCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $manager): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'is_platform_manager' => $manager,
        ]);
    }

    public function test_non_platform_manager_is_forbidden(): void
    {
        Sanctum::actingAs($this->user(false));

        $this->getJson('/api/v1/platform/status')->assertForbidden();
        $this->postJson('/api/v1/platform/status/check')->assertForbidden();
    }

    public function test_status_returns_latest_per_component_with_bucketed_worst_history(): void
    {
        Sanctum::actingAs($this->user(true));
        $t = now()->startOfHour()->subHour();
        // Same 5-min bucket: ok then down -> bucket is down. Latest row is the last one.
        $this->check('redis', 'ok', $t->copy()->addMinute(), 'fine');
        $this->check('redis', 'down', $t->copy()->addMinutes(2), 'gone');
        $this->check('redis', 'ok', $t->copy()->addMinutes(7), 'back');
        $this->check('loki', 'unknown', $t->copy()->addMinute(), 'Not running (optional)');
        PlatformHealthCheck::where('component', 'redis')->first(); // sanity
        $this->check('redis', 'ok', now()->subHours(30), 'too old');

        $data = $this->getJson('/api/v1/platform/status')->assertOk()->json('data');

        $redis = collect($data['components'])->firstWhere('component', 'redis');
        $this->assertSame('ok', $redis['status']);
        $this->assertSame('back', $redis['detail']);
        $this->assertSame(['down', 'ok'], array_column($redis['history'], 'status'));
        $loki = collect($data['components'])->firstWhere('component', 'loki');
        $this->assertTrue($loki['optional']);
        $this->assertSame('unknown', $loki['status']);
        $this->assertSame(15, count($data['components']));
        $this->assertSame(15, array_sum($data['summary']));
    }

    public function test_check_now_runs_stores_and_returns_snapshot(): void
    {
        Sanctum::actingAs($this->user(true));
        Http::fake(['*' => Http::response('ok')]);
        Storage::fake('recordings');
        Storage::fake('cdr_archive');
        Queue::fake();

        $data = $this->postJson('/api/v1/platform/status/check')->assertOk()->json('data');

        $redis = collect($data['components'])->firstWhere('component', 'redis');
        $this->assertSame('ok', $redis['status']);
        $this->assertCount(1, $redis['history']);
        $this->assertSame(15, PlatformHealthCheck::count());
    }

    public function test_check_now_is_throttled(): void
    {
        Sanctum::actingAs($this->user(true));
        Http::fake(['*' => Http::response('ok')]);
        Storage::fake('recordings');
        Storage::fake('cdr_archive');
        Queue::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/platform/status/check')->assertOk();
        }
        $this->postJson('/api/v1/platform/status/check')->assertStatus(429);
    }

    private function check(string $component, string $status, \DateTimeInterface $at, string $msg): void
    {
        PlatformHealthCheck::create([
            'component' => $component, 'status' => $status, 'latency_ms' => 5,
            'detail' => ['message' => $msg], 'checked_at' => $at,
        ]);
    }
}
