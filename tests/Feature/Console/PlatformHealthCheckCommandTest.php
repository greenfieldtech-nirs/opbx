<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PlatformHealthCheck;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_stores_results_and_writes_scheduler_heartbeat(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        Storage::fake('recordings');
        Storage::fake('cdr_archive');
        Queue::fake();

        $this->artisan('platform:health-check')->assertSuccessful();

        $this->assertSame(count(PlatformHealthService::COMPONENTS), PlatformHealthCheck::count());
        $this->assertNotNull(Cache::get(PlatformHealthService::HEARTBEAT_KEY));
        $this->assertSame('ok', PlatformHealthCheck::where('component', 'scheduler')->value('status'));
    }

    public function test_command_is_scheduled_every_minute_safely(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'platform:health-check'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
}
