<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ResourceInUseDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @return array{organization_id: int} */
    private function o(): array
    {
        return ['organization_id' => $this->org->id];
    }

    /** Assert the delete is rejected with 409 and a structured list that contains the given type/id/name. */
    private function assertBlocked(string $url, string $refType, int $refId, string $refName): void
    {
        $response = $this->deleteJson($url)->assertStatus(409)->assertJsonStructure(['error', 'message', 'references' => [['type', 'id', 'name', 'detail']]]);
        $response->assertJsonFragment(['type' => $refType, 'id' => $refId, 'name' => $refName]);
        $this->assertStringContainsString($refName, (string) $response->json('message'));
    }

    private function extension(): Extension
    {
        return Extension::factory()->create($this->o() + ['type' => ExtensionType::FORWARD]);
    }

    public function test_in_use_ring_group_is_blocked_then_deletable(): void
    {
        $group = RingGroup::factory()->create($this->o());
        $did = DidNumber::factory()->routeToRingGroup($group)->create($this->o());
        $this->assertBlocked("/api/v1/ring-groups/{$group->id}", 'did_number', $did->id, $did->phone_number);

        $did->delete();
        $queue = CallQueue::factory()->create($this->o() + ['fallback_ring_group_id' => $group->id]);
        $this->assertBlocked("/api/v1/ring-groups/{$group->id}", 'call_queue', $queue->id, $queue->name);

        $queue->update(['fallback_ring_group_id' => null]);
        $menu = IvrMenu::factory()->create($this->o());
        DB::table('ivr_menu_options')->insert(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'description' => 'x', 'destination_type' => 'ring_group', 'destination_id' => $group->id, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertBlocked("/api/v1/ring-groups/{$group->id}", 'ivr_menu', $menu->id, $menu->name);

        DB::table('ivr_menu_options')->delete();
        $this->deleteJson("/api/v1/ring-groups/{$group->id}")->assertNoContent();
    }

    public function test_ring_group_falling_back_to_itself_does_not_block_its_own_delete(): void
    {
        $group = RingGroup::factory()->create($this->o());
        $group->update(['fallback_ring_group_id' => $group->id]);

        $this->deleteJson("/api/v1/ring-groups/{$group->id}")->assertNoContent();
    }

    public function test_in_use_extension_is_blocked_by_member_fallback_and_business_hours(): void
    {
        $extension = $this->extension();
        $group = RingGroup::factory()->create($this->o());
        RingGroupMember::factory()->create(['ring_group_id' => $group->id, 'extension_id' => $extension->id]);
        $this->assertBlocked("/api/v1/extensions/{$extension->id}", 'ring_group', $group->id, $group->name);

        DB::table('ring_group_members')->delete();
        $queue = CallQueue::factory()->create($this->o() + ['fallback_extension_id' => $extension->id]);
        $this->assertBlocked("/api/v1/extensions/{$extension->id}", 'call_queue', $queue->id, $queue->name);

        $queue->update(['fallback_extension_id' => null]);
        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $hours->update(['open_hours_action_type' => 'extension', 'open_hours_action' => ['target_id' => "ext-{$extension->id}"]]);
        $response = $this->deleteJson("/api/v1/extensions/{$extension->id}")->assertStatus(409);
        $response->assertJsonFragment(['type' => 'business_hours', 'id' => $hours->id, 'detail' => 'open hours action']);

        $hours->update(['open_hours_action' => ['target_id' => 'ext-0']]);
        $this->deleteJson("/api/v1/extensions/{$extension->id}")->assertNoContent();
    }

    public function test_in_use_ivr_menu_is_blocked_by_failover_and_ring_group_fallback(): void
    {
        $menu = IvrMenu::factory()->create($this->o());
        $other = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'ivr_menu', 'failover_destination_id' => $menu->id]);
        $this->assertBlocked("/api/v1/ivr-menus/{$menu->id}", 'ivr_menu', $other->id, $other->name);

        $other->update(['failover_destination_type' => 'hangup', 'failover_destination_id' => null]);
        $group = RingGroup::factory()->create($this->o() + ['fallback_ivr_menu_id' => $menu->id]);
        $this->assertBlocked("/api/v1/ivr-menus/{$menu->id}", 'ring_group', $group->id, $group->name);

        $group->update(['fallback_ivr_menu_id' => null]);
        $this->deleteJson("/api/v1/ivr-menus/{$menu->id}")->assertNoContent();
    }

    public function test_in_use_conference_room_is_blocked_by_call_tracking_destination(): void
    {
        $room = ConferenceRoom::factory()->create($this->o());
        $campaign = CallTrackingCampaign::factory()->create($this->o() + ['destination_type' => 'conference_room', 'destination_config' => ['conference_room_id' => $room->id]]);
        $this->assertBlocked("/api/v1/conference-rooms/{$room->id}", 'call_tracking_campaign', $campaign->id, $campaign->name);

        $campaign->update(['destination_type' => 'forward', 'destination_config' => ['forward_to' => '+15550000000']]);
        $this->deleteJson("/api/v1/conference-rooms/{$room->id}")->assertNoContent();
    }

    public function test_in_use_call_queue_is_blocked_by_ring_group_fallback(): void
    {
        $queue = CallQueue::factory()->create($this->o());
        $group = RingGroup::factory()->create($this->o() + ['fallback_call_queue_id' => $queue->id]);
        $this->assertBlocked("/api/v1/call-queues/{$queue->id}", 'ring_group', $group->id, $group->name);

        $group->update(['fallback_call_queue_id' => null]);
        $this->deleteJson("/api/v1/call-queues/{$queue->id}")->assertNoContent();
    }

    public function test_in_use_business_hours_is_blocked_by_did_routing(): void
    {
        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $did = DidNumber::factory()->create($this->o() + ['routing_type' => 'business_hours', 'routing_config' => ['business_hours_schedule_id' => $hours->id]]);
        $this->assertBlocked("/api/v1/business-hours/{$hours->id}", 'did_number', $did->id, $did->phone_number);

        $did->update(['routing_config' => ['business_hours_schedule_id' => 0]]);
        $this->deleteJson("/api/v1/business-hours/{$hours->id}")->assertNoContent();
    }

    public function test_in_use_load_balancer_is_blocked_and_soft_deleted_referrers_are_ignored(): void
    {
        $balancer = AiAssistantLoadBalancer::factory()->create($this->o());
        $group = RingGroup::factory()->create($this->o() + ['fallback_ai_load_balancer_id' => $balancer->id]);
        $this->assertBlocked("/api/v1/ai-assistant-load-balancers/{$balancer->id}", 'ring_group', $group->id, $group->name);

        $group->update(['fallback_ai_load_balancer_id' => null]);
        $this->deleteJson("/api/v1/ai-assistant-load-balancers/{$balancer->id}")->assertNoContent();
    }

    public function test_in_use_ai_assistant_is_blocked_by_balancer_member_and_queue_fallback(): void
    {
        $assistant = AiAssistant::factory()->create($this->o());
        $balancer = AiAssistantLoadBalancer::factory()->create($this->o());
        $member = AiAssistantLoadBalancerMember::factory()->create(['load_balancer_id' => $balancer->id, 'ai_assistant_id' => $assistant->id]);
        $this->assertBlocked("/api/v1/ai-assistants/{$assistant->id}", 'ai_load_balancer', $balancer->id, $balancer->name);

        // A soft-deleted load balancer no longer routes anything.
        $balancer->delete();
        $queue = CallQueue::factory()->create($this->o() + ['fallback_ai_assistant_id' => $assistant->id]);
        $this->assertBlocked("/api/v1/ai-assistants/{$assistant->id}", 'call_queue', $queue->id, $queue->name);

        $queue->update(['fallback_ai_assistant_id' => null]);
        $member->delete();
        $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}")->assertNoContent();
    }

    public function test_history_only_references_never_block(): void
    {
        $user = User::factory()->create($this->o() + ['role' => UserRole::PBX_USER]);
        Recording::factory()->create($this->o() + ['created_by' => $user->id, 'updated_by' => $user->id]);
        $group = RingGroup::factory()->create($this->o());

        $this->deleteJson("/api/v1/ring-groups/{$group->id}")->assertNoContent();
        $this->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();
    }

    public function test_references_in_another_organization_do_not_block(): void
    {
        $group = RingGroup::factory()->create($this->o());
        $otherOrg = Organization::factory()->create();
        DidNumber::factory()->create(['organization_id' => $otherOrg->id, 'routing_type' => 'ring_group', 'routing_config' => ['ring_group_id' => $group->id]]);

        $this->deleteJson("/api/v1/ring-groups/{$group->id}")->assertNoContent();
    }
}
