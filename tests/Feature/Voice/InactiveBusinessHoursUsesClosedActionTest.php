<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\BusinessHoursActionType;
use App\Enums\BusinessHoursStatus;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\CallTracking\CallTrackingDestinationResolver;
use App\Services\VoiceRouting\InboundRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class InactiveBusinessHoursUsesClosedActionTest extends TestCase
{
    use RefreshDatabase;

    private BusinessHoursSchedule $schedule;

    private CallQueue $closedQueue;

    protected function setUp(): void
    {
        parent::setUp();

        $orgId = Organization::factory()->create()->id;
        $this->closedQueue = CallQueue::factory()->create(['organization_id' => $orgId]);
        // Factory hours: Mon-Fri 09:00-17:00 -> Tue 10:00 UTC is inside open hours.
        $this->schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $orgId,
            'timezone' => 'UTC',
            'status' => BusinessHoursStatus::INACTIVE,
            'closed_hours_action_type' => 'call_queue',
            'closed_hours_action' => ['target_id' => 'queue-'.$this->closedQueue->id],
        ]);
        Carbon::setTestNow('2026-10-13 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inactive_schedule_is_never_open_and_reports_closed(): void
    {
        $this->assertFalse($this->schedule->isCurrentlyOpen());
        $this->assertSame('closed', $this->schedule->current_status);

        $this->schedule->update(['status' => BusinessHoursStatus::ACTIVE]);
        $this->assertTrue($this->schedule->fresh()->isCurrentlyOpen());
        $this->assertSame('open', $this->schedule->fresh()->current_status);
    }

    public function test_current_routing_helpers_use_the_closed_action(): void
    {
        $this->assertSame(BusinessHoursActionType::CALL_QUEUE, $this->schedule->getCurrentRoutingType());
        $this->assertSame('queue-'.$this->closedQueue->id, $this->schedule->getCurrentRoutingTargetId());
        $this->assertSame(
            ['type' => 'call_queue', 'config' => ['call_queue_id' => $this->closedQueue->id]],
            $this->schedule->getCurrentRouting()
        );
    }

    public function test_did_inbound_routing_uses_the_closed_action(): void
    {
        $did = DidNumber::factory()->create([
            'organization_id' => $this->schedule->organization_id,
            'routing_type' => 'business_hours',
            'routing_config' => ['business_hours_schedule_id' => $this->schedule->id],
        ]);

        $destination = [];
        $method = new ReflectionMethod(InboundRoutingService::class, 'resolveBusinessHoursDestination');
        $args = [$did, &$destination];
        $method->invokeArgs(app(InboundRoutingService::class), $args);

        $this->assertSame($this->closedQueue->id, $destination['call_queue']->id ?? null);
    }

    public function test_call_tracking_destination_uses_the_closed_action_of_an_inactive_schedule(): void
    {
        $campaign = CallTrackingCampaign::factory()->toBusinessHours($this->schedule->id)->create([
            'organization_id' => $this->schedule->organization_id,
        ]);

        $resolved = app(CallTrackingDestinationResolver::class)->resolve($campaign);

        $this->assertNotNull($resolved);
        $this->assertSame($this->closedQueue->id, $resolved['call_queue']->id ?? null);
    }
}
