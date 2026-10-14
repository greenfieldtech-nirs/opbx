<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\BusinessHoursActionType;
use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Http\Controllers\Voice\AlbsFollowThroughController;
use App\Http\Controllers\Voice\QueuePollController;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\AiAssistant;
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
use App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Every routing path to an inactive AI assistant ends the call with a spoken message instead of connecting it. */
final class InactiveAiAssistantRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'The AI assistant you are trying to reach is unavailable.';

    private Organization $org;

    private AiAssistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->assistant = AiAssistant::factory()->inactive()->create(['organization_id' => $this->org->id]);
    }

    private function request(): Request
    {
        return Request::create('/voice', 'POST', ['CallSid' => 'CA123', 'From' => '+15550001', 'To' => '+15550002']);
    }

    /** Spoken message + hangup, and the provider is never dialled or streamed to. */
    private function assertEnded(Response $response, ?string $message = self::MESSAGE): void
    {
        $xml = (string) $response->getContent();
        $this->assertStringContainsString('<Say', $xml);
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('<Dial', $xml);
        $this->assertStringNotContainsString('<Stream', $xml);
        $this->assertStringNotContainsString((string) ($this->assistant->configuration['phone_number'] ?? 'n/a'), $xml);
        if ($message !== null) {
            $this->assertStringContainsString($message, $xml);
        }
    }

    private function aiExtension(): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ExtensionType::AI_ASSISTANT,
            'ai_assistant_id' => $this->assistant->id,
            'configuration' => ['ai_assistant_id' => $this->assistant->id],
        ]);
    }

    /** The routing services as the app wires them (with the strategies registered). */
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

    public function test_an_active_assistant_still_connects(): void
    {
        $this->assistant->update(['status' => 'active']);
        $did = DidNumber::factory()->create(['organization_id' => $this->org->id, 'routing_type' => 'ai_assistant', 'routing_config' => ['ai_assistant_id' => $this->assistant->id]]);

        $xml = (string) $this->inbound()->routeDidCall($this->request(), $did)->getContent();
        $this->assertStringContainsString('<Dial', $xml);
    }

    public function test_phone_number_routed_to_the_assistant(): void
    {
        $did = DidNumber::factory()->create(['organization_id' => $this->org->id, 'routing_type' => 'ai_assistant', 'routing_config' => ['ai_assistant_id' => $this->assistant->id]]);

        $this->assertEnded($this->inbound()->routeDidCall($this->request(), $did));
    }

    public function test_ai_assistant_extension(): void
    {
        $this->assertEnded(app(AiAgentRoutingStrategy::class)->route($this->request(), new DidNumber, ['extension' => $this->aiExtension()]));
    }

    public function test_business_hours_on_a_phone_number_and_in_an_ivr(): void
    {
        $hours = BusinessHoursSchedule::factory()->create(['organization_id' => $this->org->id]);
        $action = ['type' => 'ai_assistant', 'target_id' => "ai-{$this->assistant->id}"];
        $hours->update([
            'open_hours_action_type' => 'ai_assistant', 'open_hours_action' => $action,
            'closed_hours_action_type' => 'ai_assistant', 'closed_hours_action' => $action,
        ]);
        $did = DidNumber::factory()->routeToBusinessHours($hours)->create(['organization_id' => $this->org->id]);

        $this->assertEnded($this->inbound()->routeDidCall($this->request(), $did));
        $this->assertEnded($this->ivr()->routeBusinessHoursAction(
            $this->request(), BusinessHoursActionType::AI_ASSISTANT, "ai-{$this->assistant->id}", $this->org->id,
        ));
    }

    public function test_ivr_option_and_failover(): void
    {
        $menu = IvrMenu::factory()->create(['organization_id' => $this->org->id, 'failover_destination_type' => 'ai_assistant', 'failover_destination_id' => $this->assistant->id]);
        $optionId = DB::table('ivr_menu_options')->insertGetId(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'description' => 'x', 'destination_type' => 'ai_assistant', 'destination_id' => $this->assistant->id, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = $this->ivr();

        // An option to an unusable destination already says "Destination is no longer available." and hangs up.
        $this->assertEnded($service->routeToOptionDestination($this->request(), IvrMenuOption::find($optionId), $menu->fresh()), null);
        $this->assertEnded($service->routeToFailoverDestination($this->request(), $menu->fresh()));
    }

    public function test_ring_group_fallback(): void
    {
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $group->update(['fallback_action' => RingGroupFallbackAction::AI_ASSISTANT, 'fallback_ai_assistant_id' => $this->aiExtension()->id]);

        $this->assertEnded($this->invoke(app(RingGroupRoutingStrategy::class), 'handleFallback', $group->fresh(), $this->request()));
    }

    public function test_call_queue_and_load_balancer_fallbacks(): void
    {
        $this->aiExtension();
        $queue = CallQueue::factory()->create(['organization_id' => $this->org->id]);
        $queue->update(['fallback_action' => RingGroupFallbackAction::AI_ASSISTANT, 'fallback_ai_assistant_id' => $this->assistant->id]);
        $this->assertEnded($this->invoke(app(QueuePollController::class), 'handleFallback', $queue->fresh(), \Mockery::mock(QueueCallbackRequest::class)), null);

        $albs = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $albs->update(['fallback_action' => RingGroupFallbackAction::AI_ASSISTANT, 'fallback_ai_assistant_id' => $this->assistant->id]);
        $this->assertEnded($this->invoke(app(AiLoadBalancerRoutingStrategy::class), 'handleFallback', $albs->fresh(), $this->request()), null);
        $this->assertEnded($this->invoke(app(AlbsFollowThroughController::class), 'executeFallback', $albs->fresh(), $this->request()), null);
    }
}
