<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use App\Services\ResourceReferenceChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserDeleteImpactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $victim;

    private Extension $ext;

    private Extension $control;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        $this->victim = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->ext = Extension::factory()->create($this->o() + ['type' => ExtensionType::USER, 'user_id' => $this->victim->id]);
        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::PBX_USER]);
        $this->control = Extension::factory()->create($this->o() + ['type' => ExtensionType::USER, 'user_id' => $other->id]);
        Sanctum::actingAs($this->admin);
    }

    /** @return array{organization_id: int} */
    private function o(): array
    {
        return ['organization_id' => $this->org->id];
    }

    /** Every kind of reference to $this->ext, plus control references to $this->control. @return array<string, mixed> */
    private function wire(): array
    {
        $full = RingGroup::factory()->create($this->o());
        RingGroupMember::factory()->create(['ring_group_id' => $full->id, 'extension_id' => $this->ext->id]);
        RingGroupMember::factory()->create(['ring_group_id' => $full->id, 'extension_id' => $this->control->id]);
        $solo = RingGroup::factory()->create($this->o());
        RingGroupMember::factory()->create(['ring_group_id' => $solo->id, 'extension_id' => $this->ext->id]);
        $rgFallback = RingGroup::factory()->create($this->o() + ['fallback_action' => 'extension', 'fallback_extension_id' => $this->ext->id]);

        $busyQueue = CallQueue::factory()->create($this->o());
        DB::table('call_queue_agents')->insert([
            ['organization_id' => $this->org->id, 'call_queue_id' => $busyQueue->id, 'user_id' => $this->victim->id, 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'call_queue_id' => $busyQueue->id, 'user_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $soloQueue = CallQueue::factory()->create($this->o());
        DB::table('call_queue_agents')->insert(['organization_id' => $this->org->id, 'call_queue_id' => $soloQueue->id, 'user_id' => $this->victim->id, 'created_at' => now(), 'updated_at' => now()]);
        $queueFallback = CallQueue::factory()->create($this->o() + ['fallback_action' => 'extension', 'fallback_extension_id' => $this->ext->id]);

        $albs = AiAssistantLoadBalancer::factory()->create($this->o() + ['fallback_action' => 'extension', 'fallback_extension_id' => $this->ext->id]);

        $menu = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'extension', 'failover_destination_id' => $this->ext->id]);
        $now = now();
        DB::table('ivr_menu_options')->insert([
            ['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'description' => 'x', 'destination_type' => 'extension', 'destination_id' => $this->ext->id, 'priority' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['ivr_menu_id' => $menu->id, 'input_digits' => '2', 'description' => 'y', 'destination_type' => 'extension', 'destination_id' => $this->control->id, 'priority' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $did = DidNumber::factory()->routeToExtension($this->ext)->create($this->o());
        $controlDid = DidNumber::factory()->routeToExtension($this->control)->create($this->o());
        $campaign = CallTrackingCampaign::factory()->toExtension($this->ext->id)->create($this->o());

        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $hours->update([
            'open_hours_action_type' => 'extension', 'open_hours_action' => ['target_id' => "ext-{$this->ext->id}"],
            'closed_hours_action_type' => 'extension', 'closed_hours_action' => ['target_id' => "ext-{$this->control->id}"],
        ]);

        return compact('full', 'solo', 'rgFallback', 'busyQueue', 'soloQueue', 'queueFallback', 'albs', 'menu', 'did', 'controlDid', 'campaign', 'hours');
    }

    public function test_preview_lists_exactly_what_delete_then_does(): void
    {
        $r = $this->wire();

        $data = $this->getJson("/api/v1/users/{$this->victim->id}/delete-impact")->assertOk()->json('data');

        $this->assertSame([$this->ext->id], array_column($data['extensions'], 'id'));
        $this->assertEqualsCanonicalizing(
            [['ring_group', $r['full']->id, false], ['ring_group', $r['solo']->id, true], ['call_queue', $r['busyQueue']->id, false], ['call_queue', $r['soloQueue']->id, true]],
            array_map(fn (array $m): array => [$m['type'], $m['id'], $m['becomes_empty']], $data['memberships']),
        );
        $this->assertSame(2, $data['empty_groups']);
        $this->assertEqualsCanonicalizing(
            [
                ['ring_group', $r['rgFallback']->id, 'fallback'], ['call_queue', $r['queueFallback']->id, 'fallback'], ['ai_load_balancer', $r['albs']->id, 'fallback'],
                ['ivr_menu', $r['menu']->id, 'menu option 1'], ['ivr_menu', $r['menu']->id, 'failover'],
                ['did_number', $r['did']->id, 'routing target'], ['call_tracking_campaign', $r['campaign']->id, 'routing target'],
                ['business_hours', $r['hours']->id, 'open hours action'],
            ],
            array_map(fn (array $x): array => [$x['type'], $x['id'], $x['detail']], $data['routes']),
        );

        // The preview wrote nothing.
        $this->assertSame($this->victim->id, $this->ext->fresh()->user_id);
        $this->assertSame(0, DB::table('ring_groups')->where('fallback_action', 'unavailable')->count());

        $this->deleteJson("/api/v1/users/{$this->victim->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $this->victim->id]);
        $this->assertNull($this->ext->fresh()->user_id, 'extension kept and unassigned');
        $this->assertSame(1, DB::table('ring_group_members')->where('ring_group_id', $r['full']->id)->count());
        $this->assertSame(0, DB::table('ring_group_members')->where('ring_group_id', $r['solo']->id)->count());
        $this->assertSame(1, DB::table('call_queue_agents')->where('call_queue_id', $r['busyQueue']->id)->count());
        $this->assertSame(0, DB::table('call_queue_agents')->where('call_queue_id', $r['soloQueue']->id)->count());

        $this->assertDatabaseHas('ring_groups', ['id' => $r['rgFallback']->id, 'fallback_action' => 'unavailable', 'fallback_extension_id' => null]);
        $this->assertDatabaseHas('call_queues', ['id' => $r['queueFallback']->id, 'fallback_action' => 'unavailable', 'fallback_extension_id' => null]);
        $this->assertDatabaseHas('ai_assistant_load_balancers', ['id' => $r['albs']->id, 'fallback_action' => 'unavailable', 'fallback_extension_id' => null]);
        $this->assertDatabaseHas('ivr_menus', ['id' => $r['menu']->id, 'failover_destination_type' => 'unavailable']);
        $this->assertSame(1, DB::table('ivr_menu_options')->where('ivr_menu_id', $r['menu']->id)->where('destination_type', 'unavailable')->count());
        $this->assertDatabaseHas('did_numbers', ['id' => $r['did']->id, 'routing_type' => 'unavailable']);
        $this->assertDatabaseHas('call_tracking_campaigns', ['id' => $r['campaign']->id, 'destination_type' => 'unavailable']);
        $hours = DB::table('business_hours_schedules')->where('id', $r['hours']->id)->first();
        $this->assertSame('unavailable', $hours->open_hours_action_type);
        $this->assertSame('extension', $hours->closed_hours_action_type, 'control action untouched');

        // Nothing routing-wise points at the kept extension any more (the checker is the source of truth).
        $this->assertFalse(app(ResourceReferenceChecker::class)->checkReferences('extension', $this->ext->id, $this->org->id)['has_references']);
        // Control extension's routes untouched.
        $this->assertDatabaseHas('did_numbers', ['id' => $r['controlDid']->id, 'routing_type' => 'extension']);
        $this->assertSame(1, DB::table('ivr_menu_options')->where('destination_id', $this->control->id)->where('destination_type', 'extension')->count());
        // Exactly as many switched routes as the preview listed.
        $switched = DB::table('ring_groups')->where('fallback_action', 'unavailable')->count()
            + DB::table('call_queues')->where('fallback_action', 'unavailable')->count()
            + DB::table('ai_assistant_load_balancers')->where('fallback_action', 'unavailable')->count()
            + DB::table('ivr_menu_options')->where('destination_type', 'unavailable')->count()
            + DB::table('ivr_menus')->where('failover_destination_type', 'unavailable')->count()
            + DB::table('did_numbers')->where('routing_type', 'unavailable')->count()
            + DB::table('call_tracking_campaigns')->where('destination_type', 'unavailable')->count()
            + DB::table('business_hours_schedules')->where('open_hours_action_type', 'unavailable')->count();
        $this->assertSame(count($data['routes']), $switched);
    }

    public function test_failed_delete_rolls_back_every_change(): void
    {
        $r = $this->wire();
        // The last-owner guard fails the delete after the impact ran: everything must roll back.
        $this->victim->update(['role' => UserRole::OWNER]);
        $this->admin->update(['role' => UserRole::PBX_ADMIN]);

        $this->deleteJson("/api/v1/users/{$this->victim->id}")->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $this->victim->id]);
        $this->assertSame($this->victim->id, $this->ext->fresh()->user_id);
        $this->assertDatabaseHas('did_numbers', ['id' => $r['did']->id, 'routing_type' => 'extension']);
        $this->assertSame(1, DB::table('ring_group_members')->where('ring_group_id', $r['solo']->id)->count());
        $this->assertSame(0, DB::table('ring_groups')->where('fallback_action', 'unavailable')->count());
    }

    public function test_user_without_extension_has_no_changes(): void
    {
        $plain = User::factory()->create($this->o() + ['role' => UserRole::PBX_USER]);

        $this->getJson("/api/v1/users/{$plain->id}/delete-impact")->assertOk()
            ->assertJsonPath('data.has_changes', false)->assertJsonPath('data.extensions', []);
        $this->deleteJson("/api/v1/users/{$plain->id}")->assertNoContent();
    }

    public function test_preview_authorization_and_tenant_isolation(): void
    {
        Sanctum::actingAs(User::factory()->create($this->o() + ['role' => UserRole::PBX_USER]));
        $this->getJson("/api/v1/users/{$this->victim->id}/delete-impact")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/users/{$this->admin->id}/delete-impact")->assertForbidden(); // cannot delete self

        $foreign = User::factory()->create(['organization_id' => Organization::factory()->create()->id, 'role' => UserRole::PBX_USER]);
        $this->assertContains($this->getJson("/api/v1/users/{$foreign->id}/delete-impact")->status(), [403, 404]);
    }

    public function test_other_organizations_are_untouched(): void
    {
        $this->wire();
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => UserRole::PBX_USER]);
        $otherExt = Extension::factory()->create(['organization_id' => $otherOrg->id, 'type' => ExtensionType::USER, 'user_id' => $otherUser->id]);
        $otherDid = DidNumber::factory()->routeToExtension($otherExt)->create(['organization_id' => $otherOrg->id]);
        $otherGroup = RingGroup::factory()->create(['organization_id' => $otherOrg->id]);
        RingGroupMember::factory()->create(['ring_group_id' => $otherGroup->id, 'extension_id' => $otherExt->id]);

        $this->deleteJson("/api/v1/users/{$this->victim->id}")->assertNoContent();

        $this->assertDatabaseHas('did_numbers', ['id' => $otherDid->id, 'routing_type' => 'extension']);
        $this->assertSame($otherUser->id, $otherExt->fresh()->user_id);
        $this->assertSame(1, DB::table('ring_group_members')->where('ring_group_id', $otherGroup->id)->count());
    }
}
