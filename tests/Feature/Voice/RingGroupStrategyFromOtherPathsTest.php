<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Http\Controllers\Voice\AlbsFollowThroughController;
use App\Models\AiAssistantLoadBalancer;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Services\VoiceRouting\BusinessHoursRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** A ring group reached from a business-hours action or an AI load balancer fallback uses its own strategy and fallback. */
final class RingGroupStrategyFromOtherPathsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private RingGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        CloudonixSettings::factory()->create(['organization_id' => $this->org->id, 'webhook_base_url' => 'https://example.com']);
        $this->group = RingGroup::factory()->sequential()->create(['organization_id' => $this->org->id, 'ring_turns' => 1]);
        foreach (['2001', '2002'] as $i => $number) {
            $ext = Extension::create(['organization_id' => $this->org->id, 'extension_number' => $number, 'type' => ExtensionType::USER, 'status' => UserStatus::ACTIVE, 'configuration' => []]);
            RingGroupMember::create(['ring_group_id' => $this->group->id, 'extension_id' => $ext->id, 'priority' => $i + 1]);
        }
    }

    private function request(): Request
    {
        return Request::create('/voice/route', 'POST', ['CallSid' => 'CA1', 'From' => '+15550001', 'To' => '+15550002', '_organization_id' => $this->org->id]);
    }

    private function viaBusinessHours(): string
    {
        $method = new \ReflectionMethod(BusinessHoursRoutingService::class, 'routeArrayAction');

        return (string) $method->invoke(app(BusinessHoursRoutingService::class), ['type' => 'ring_group', 'config' => ['ring_group_id' => $this->group->id]], $this->org->id, 'CA1', $this->request())->getContent();
    }

    private function viaLoadBalancerFallback(): string
    {
        $albs = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $albs->update(['fallback_action' => RingGroupFallbackAction::RING_GROUP, 'fallback_ring_group_id' => $this->group->id]);
        $method = new \ReflectionMethod(AlbsFollowThroughController::class, 'executeFallback');

        return (string) $method->invoke(app(AlbsFollowThroughController::class), $albs->fresh(), $this->request())->getContent();
    }

    private function assertSequentialFirstMember(string $xml): void
    {
        $this->assertStringContainsString('2001', $xml);
        $this->assertStringNotContainsString('2002', $xml);
    }

    private function assertGroupFallback(string $xml): void
    {
        $this->assertStringContainsString("Destination doesn't exist", $xml);
    }

    public function test_business_hours_uses_the_group_strategy(): void
    {
        $this->assertSequentialFirstMember($this->viaBusinessHours());
    }

    public function test_load_balancer_fallback_uses_the_group_strategy(): void
    {
        $this->assertSequentialFirstMember($this->viaLoadBalancerFallback());
    }

    public function test_both_paths_use_the_group_fallback_when_no_member_is_available(): void
    {
        Extension::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['status' => UserStatus::INACTIVE]);
        $this->group->update(['fallback_action' => 'unavailable']);

        $this->assertGroupFallback($this->viaBusinessHours());
        $this->assertGroupFallback($this->viaLoadBalancerFallback());
    }
}
