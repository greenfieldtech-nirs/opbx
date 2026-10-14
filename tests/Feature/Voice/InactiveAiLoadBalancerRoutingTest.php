<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\BusinessHoursActionType;
use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Services\VoiceRouting\InboundRoutingService;
use App\Services\VoiceRouting\IvrRoutingService;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Every routing path to an inactive AI load balancer ends the call with a spoken message; the load balancer's own
 * fallback is not used (it stays for an active load balancer whose members all fail).
 */
final class InactiveAiLoadBalancerRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'The AI load balancer you are trying to reach is unavailable.';

    private Organization $org;

    private AiAssistantLoadBalancer $albs;

    private RingGroup $fallbackGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        // The fallback a wrong implementation would use: ringing a ring group.
        $this->fallbackGroup = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $this->albs = AiAssistantLoadBalancer::factory()->inactive()->create([
            'organization_id' => $this->org->id,
            'fallback_action' => RingGroupFallbackAction::RING_GROUP,
            'fallback_ring_group_id' => $this->fallbackGroup->id,
        ]);
    }

    private function request(): Request
    {
        return Request::create('/voice', 'POST', ['CallSid' => 'CA123', 'From' => '+15550001', 'To' => '+15550002']);
    }

    private function assertEnded(Response $response, ?string $message = self::MESSAGE): void
    {
        $xml = (string) $response->getContent();
        $this->assertStringContainsString('<Say', $xml);
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Stream', $xml);
        if ($message !== null) {
            $this->assertStringContainsString($message, $xml);
        }
    }

    private function inbound(): InboundRoutingService
    {
        return $this->managed('inboundRouting');
    }

    private function ivr(): IvrRoutingService
    {
        return $this->managed('ivrRouting');
    }

    private function managed(string $property): object
    {
        $manager = app(VoiceRoutingManager::class);

        return (new \ReflectionProperty($manager, $property))->getValue($manager);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    public function test_phone_number_routed_to_the_load_balancer(): void
    {
        $did = DidNumber::factory()->create(['organization_id' => $this->org->id, 'routing_type' => 'ai_load_balancer', 'routing_config' => ['ai_load_balancer_id' => $this->albs->id]]);

        $this->assertEnded($this->inbound()->routeDidCall($this->request(), $did));
    }

    public function test_load_balancer_extension(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ExtensionType::AI_LOAD_BALANCER,
            'configuration' => ['ai_load_balancer_id' => $this->albs->id],
        ]);

        $this->assertEnded(app(AiLoadBalancerRoutingStrategy::class)->route($this->request(), new DidNumber, ['extension' => $extension]));
    }

    public function test_business_hours_on_a_phone_number_and_in_an_ivr(): void
    {
        $hours = BusinessHoursSchedule::factory()->create(['organization_id' => $this->org->id]);
        $action = ['type' => 'ai_load_balancer', 'target_id' => "albs-{$this->albs->id}"];
        $hours->update([
            'open_hours_action_type' => 'ai_load_balancer', 'open_hours_action' => $action,
            'closed_hours_action_type' => 'ai_load_balancer', 'closed_hours_action' => $action,
        ]);
        $did = DidNumber::factory()->routeToBusinessHours($hours)->create(['organization_id' => $this->org->id]);

        $this->assertEnded($this->inbound()->routeDidCall($this->request(), $did));
        $this->assertEnded($this->ivr()->routeBusinessHoursAction(
            $this->request(), BusinessHoursActionType::AI_LOAD_BALANCER, "albs-{$this->albs->id}", $this->org->id,
        ));
    }

    public function test_ivr_option_and_failover(): void
    {
        $menu = IvrMenu::factory()->create(['organization_id' => $this->org->id, 'failover_destination_type' => 'ai_load_balancer', 'failover_destination_id' => $this->albs->id]);
        $optionId = DB::table('ivr_menu_options')->insertGetId(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'description' => 'x', 'destination_type' => 'ai_load_balancer', 'destination_id' => $this->albs->id, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // An option to an unusable destination already says "Destination is no longer available." and hangs up.
        $this->assertEnded($this->ivr()->routeToOptionDestination($this->request(), IvrMenuOption::find($optionId), $menu->fresh()), null);
        $this->assertEnded($this->ivr()->routeToFailoverDestination($this->request(), $menu->fresh()));
    }

    public function test_ring_group_queue_and_load_balancer_fallbacks_targeting_it(): void
    {
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $group->update(['fallback_action' => RingGroupFallbackAction::AI_LOAD_BALANCER, 'fallback_ai_load_balancer_id' => $this->albs->id]);
        $this->assertEnded($this->invoke(app(RingGroupRoutingStrategy::class), 'handleFallback', $group->fresh(), $this->request()), null);

        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $queue->update(['fallback_action' => RingGroupFallbackAction::AI_LOAD_BALANCER, 'fallback_ai_load_balancer_id' => $this->albs->id]);
        $this->assertEnded($this->invoke(app(QueuePollController::class), 'handleFallback', $queue->fresh(), \Mockery::mock(QueueCallbackRequest::class)), null);

        $other = AiAssistantLoadBalancer::factory()->create([
            'organization_id' => $this->org->id,
            'fallback_action' => RingGroupFallbackAction::AI_LOAD_BALANCER,
            'fallback_ai_load_balancer_id' => $this->albs->id,
        ]);
        $this->assertEnded(app(AiLoadBalancerRoutingStrategy::class)->handleFallbackAiLoadBalancer($other, $this->request()), null);
    }

    public function test_an_active_load_balancer_without_members_still_uses_its_fallback(): void
    {
        $this->albs->update(['status' => 'active']);
        $did = DidNumber::factory()->create(['organization_id' => $this->org->id, 'routing_type' => 'ai_load_balancer', 'routing_config' => ['ai_load_balancer_id' => $this->albs->id]]);

        $xml = (string) $this->inbound()->routeDidCall($this->request(), $did)->getContent();
        $this->assertStringNotContainsString(self::MESSAGE, $xml);
    }
}
