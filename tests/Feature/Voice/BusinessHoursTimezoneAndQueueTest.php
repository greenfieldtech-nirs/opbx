<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\VoiceRouting\InboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class BusinessHoursTimezoneAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(string $timezone, array $attrs = []): BusinessHoursSchedule
    {
        // Factory hours: Mon-Fri 09:00-17:00, weekends closed.
        return BusinessHoursSchedule::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'timezone' => $timezone,
        ] + $attrs);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_closed_uses_the_schedule_time_zone(): void
    {
        $ny = $this->schedule('America/New_York');

        // Tue 2026-10-13 20:00 UTC = 16:00 EDT -> open (UTC clock says 20:00, closed)
        Carbon::setTestNow('2026-10-13 20:00:00 UTC');
        $this->assertTrue($ny->isCurrentlyOpen());

        // 09:30 UTC = 05:30 EDT -> closed (UTC clock says open)
        Carbon::setTestNow('2026-10-13 09:30:00 UTC');
        $this->assertFalse($ny->isCurrentlyOpen());
    }

    public function test_day_of_week_follows_the_schedule_time_zone(): void
    {
        $tokyo = $this->schedule('Asia/Tokyo');

        // Mon 2026-10-12 00:30 UTC = 09:30 JST Monday -> open
        Carbon::setTestNow('2026-10-12 00:30:00 UTC');
        $this->assertTrue($tokyo->isCurrentlyOpen());

        // Sat 2026-10-17 20:00 UTC = Sun 05:00 JST -> closed; Fri 2026-10-16 23:30 UTC = Sat 08:30 JST -> closed
        Carbon::setTestNow('2026-10-16 23:30:00 UTC');
        $this->assertFalse($tokyo->isCurrentlyOpen());
    }

    public function test_dst_end_shifts_the_utc_hour_of_opening(): void
    {
        $ny = $this->schedule('America/New_York');

        // 2026-11-02 (Mon) is after US DST ended (Nov 1): 13:30 UTC = 08:30 EST -> closed
        Carbon::setTestNow('2026-11-02 13:30:00 UTC');
        $this->assertFalse($ny->isCurrentlyOpen());

        // 14:30 UTC = 09:30 EST -> open
        Carbon::setTestNow('2026-11-02 14:30:00 UTC');
        $this->assertTrue($ny->isCurrentlyOpen());

        // Before the change (Mon 2026-10-26, EDT) 13:30 UTC = 09:30 EDT -> open
        Carbon::setTestNow('2026-10-26 13:30:00 UTC');
        $this->assertTrue($ny->isCurrentlyOpen());
    }

    public function test_call_queue_action_is_converted_for_routing(): void
    {
        $schedule = $this->schedule('UTC', [
            'open_hours_action_type' => 'call_queue',
            'open_hours_action' => ['target_id' => 'queue-7'],
        ]);
        Carbon::setTestNow('2026-10-13 10:00:00 UTC');

        $this->assertSame(
            ['type' => 'call_queue', 'config' => ['call_queue_id' => 7]],
            $schedule->getCurrentRouting()
        );
    }

    public function test_did_business_hours_call_queue_action_resolves_the_queue(): void
    {
        $schedule = $this->schedule('UTC', [
            'open_hours_action_type' => 'call_queue',
            'open_hours_action' => ['target_id' => 'queue-0'],
        ]);
        $queue = CallQueue::factory()->create(['organization_id' => $schedule->organization_id]);
        $schedule->update(['open_hours_action' => ['target_id' => 'queue-'.$queue->id]]);
        Carbon::setTestNow('2026-10-13 10:00:00 UTC');

        $did = DidNumber::factory()->create([
            'organization_id' => $schedule->organization_id,
            'routing_type' => 'business_hours',
            'routing_config' => ['business_hours_schedule_id' => $schedule->id],
        ]);

        $destination = [];
        $method = new ReflectionMethod(InboundRoutingService::class, 'resolveBusinessHoursDestination');
        $args = [$did, &$destination];
        $method->invokeArgs(app(InboundRoutingService::class), $args);

        $this->assertSame($queue->id, $destination['call_queue']->id ?? null);
    }
}
