<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Http\Controllers\Voice\AlbsFollowThroughController;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Services\VoiceRouting\BusinessHoursRoutingService;
use App\Services\VoiceRouting\InboundRoutingService;
use App\Services\VoiceRouting\IvrRoutingService;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use App\Services\VoiceRouting\Strategies\CallTrackingRoutingStrategy;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** An 'unavailable' destination says "Destination doesn't exist" and hangs up, in every path that resolves destinations. */
final class UnavailableDestinationCxmlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    /** @return array{organization_id: int} */
    private function o(): array
    {
        return ['organization_id' => $this->org->id];
    }

    private function request(): Request
    {
        return Request::create('/voice', 'POST', ['CallSid' => 'CA123', 'From' => '+15550001', 'To' => '+15550002']);
    }

    private function assertUnavailable(Response $response): void
    {
        $xml = (string) $response->getContent();
        $this->assertStringContainsString("Destination doesn't exist", $xml);
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);

        return $reflection->invoke($target, ...$args);
    }

    public function test_ivr_option_and_failover(): void
    {
        $menu = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'unavailable', 'failover_destination_id' => 1]);
        $optionId = DB::table('ivr_menu_options')->insertGetId(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'description' => 'x', 'destination_type' => 'unavailable', 'destination_id' => 1, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(IvrRoutingService::class);

        $this->assertUnavailable($service->routeToOptionDestination($this->request(), IvrMenuOption::find($optionId), $menu->fresh()));
        $this->assertUnavailable($service->routeToFailoverDestination($this->request(), $menu->fresh()));
        $this->assertSame('Destination unavailable', IvrMenuOption::find($optionId)->getDestinationName());
    }

    public function test_did_routing_unavailable(): void
    {
        $did = DidNumber::factory()->create($this->o() + ['routing_type' => 'unavailable', 'routing_config' => []]);

        $this->assertUnavailable(app(InboundRoutingService::class)->routeDidCall($this->request(), $did));
    }

    public function test_did_business_hours_with_unavailable_action(): void
    {
        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $hours->update([
            'open_hours_action_type' => 'unavailable', 'open_hours_action' => ['target_id' => 'unavailable'],
            'closed_hours_action_type' => 'unavailable', 'closed_hours_action' => ['target_id' => 'unavailable'],
        ]);
        $did = DidNumber::factory()->routeToBusinessHours($hours)->create($this->o());

        $this->assertUnavailable(app(InboundRoutingService::class)->routeDidCall($this->request(), $did));

        $action = $hours->fresh()->getCurrentRouting();
        $this->assertSame('unavailable', $action['type']);
        $this->assertUnavailable($this->invoke(app(BusinessHoursRoutingService::class), 'routeArrayAction', $action, $this->org->id, 'CA123', $this->request()));
    }

    public function test_ring_group_queue_and_load_balancer_fallbacks(): void
    {
        $group = RingGroup::factory()->create($this->o());
        $group->update(['fallback_action' => 'unavailable']);
        $this->assertUnavailable($this->invoke(app(RingGroupRoutingStrategy::class), 'handleFallback', $group->fresh(), $this->request()));

        $albs = AiAssistantLoadBalancer::factory()->create($this->o());
        $albs->update(['fallback_action' => 'unavailable']);
        $this->assertUnavailable($this->invoke(app(AiLoadBalancerRoutingStrategy::class), 'handleFallback', $albs->fresh(), $this->request()));
        $this->assertUnavailable($this->invoke(app(AlbsFollowThroughController::class), 'executeFallback', $albs->fresh(), $this->request()));

        $queue = CallQueue::factory()->create($this->o());
        $queue->update(['fallback_action' => 'unavailable']);
        $this->assertUnavailable($this->invoke(app(QueuePollController::class), 'handleFallback', $queue->fresh(), \Mockery::mock(QueueCallbackRequest::class)));
    }

    public function test_call_tracking_destination(): void
    {
        $campaign = CallTrackingCampaign::factory()->create($this->o());
        $campaign->update(['destination_type' => 'unavailable', 'destination_config' => []]);
        $did = DidNumber::factory()->create($this->o() + ['routing_type' => 'call_tracking', 'routing_config' => ['call_tracking_campaign_id' => $campaign->id]]);
        CallTrackingNumber::factory()->create($this->o() + ['call_tracking_campaign_id' => $campaign->id, 'did_number_id' => $did->id]);

        $this->assertUnavailable(app(CallTrackingRoutingStrategy::class)->route($this->request(), $did, []));
    }
}
