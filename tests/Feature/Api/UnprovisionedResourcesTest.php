<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHoursSchedule;
use App\Models\CallQueue;
use App\Models\CallTrackingCampaign;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UnprovisionedResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($this->owner);
    }

    /** @return array{organization_id: int} */
    private function o(?Organization $org = null): array
    {
        return ['organization_id' => ($org ?? $this->org)->id];
    }

    /** @return array<string, mixed> */
    private function ivrPayload(array $override = []): array
    {
        return $override + [
            'name' => 'Future Menu', 'tts_text' => 'Hello', 'max_turns' => 3, 'max_timeout' => 10, 'inter_digit_timeout' => 5,
            'failover_destination_type' => 'unavailable', 'status' => 'active',
            'options' => [['input_digits' => '1', 'description' => 'Sales', 'destination_type' => 'unavailable', 'priority' => 1]],
        ];
    }

    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @return array<string, mixed> */
    private function hoursPayload(string $open, string $closed): array
    {
        $day = ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]];

        return [
            'name' => 'Hours', 'status' => 'active', 'timezone' => 'UTC',
            'open_hours_action' => ['type' => $open, 'target_id' => $open],
            'closed_hours_action' => ['type' => $closed, 'target_id' => $closed],
            'schedule' => array_fill_keys(self::DAYS, $day),
        ];
    }

    /** @return array<string, mixed> */
    private function ringGroupPayload(string $fallback, int $memberId, array $extra = []): array
    {
        return $extra + [
            'name' => 'RG', 'strategy' => 'simultaneous', 'timeout' => 30, 'ring_turns' => 1, 'status' => 'active',
            'fallback_action' => $fallback, 'members' => [['extension_id' => $memberId, 'priority' => 1]],
        ];
    }

    // ---- IVR menus -------------------------------------------------------------------------------------------

    public function test_ivr_menu_create_rejects_unavailable_failover_and_option(): void
    {
        $ext = Extension::factory()->create($this->o());
        $ok = ['destination_type' => 'extension', 'destination_id' => $ext->id];

        $this->postJson('/api/v1/ivr-menus', $this->ivrPayload(['name' => 'Ready', 'failover_destination_type' => 'hangup', 'options' => [
            ['input_digits' => '1', 'priority' => 1] + $ok,
        ]]) + [])->assertCreated();

        $this->postJson('/api/v1/ivr-menus', $this->ivrPayload(['name' => 'A', 'failover_destination_type' => 'unavailable', 'options' => [
            ['input_digits' => '1', 'priority' => 1] + $ok,
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['failover_destination_type']);

        $this->postJson('/api/v1/ivr-menus', $this->ivrPayload(['name' => 'B', 'failover_destination_type' => 'hangup', 'options' => [
            ['input_digits' => '1', 'destination_type' => 'unavailable', 'priority' => 1],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['options.0.destination_type']);

        $this->assertDatabaseMissing('ivr_menus', ['name' => 'A']);
        $this->assertDatabaseMissing('ivr_menus', ['name' => 'B']);
    }

    public function test_ivr_menu_update_accepts_unavailable_kept_switched_and_new(): void
    {
        $ext = Extension::factory()->create($this->o());
        $menu = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'unavailable']);
        DB::table('ivr_menu_options')->insert(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'destination_type' => 'unavailable', 'destination_id' => 0, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // Keep the stale unavailable option + failover, add a connected one.
        $this->putJson("/api/v1/ivr-menus/{$menu->id}", $this->ivrPayload(['name' => 'Renamed', 'options' => [
            ['input_digits' => '1', 'destination_type' => 'unavailable', 'destination_id' => 0, 'priority' => 1],
            ['input_digits' => '2', 'destination_type' => 'extension', 'destination_id' => $ext->id, 'priority' => 2],
        ]]))->assertOk()->assertJsonPath('data.is_unprovisioned', true);

        // Switch a connected option and the failover to unavailable, and add a brand-new unavailable option.
        $this->putJson("/api/v1/ivr-menus/{$menu->id}", $this->ivrPayload(['options' => [
            ['input_digits' => '2', 'destination_type' => 'unavailable', 'priority' => 1],
            ['input_digits' => '3', 'destination_type' => 'unavailable', 'priority' => 2],
        ]]))->assertOk();
        $this->assertDatabaseHas('ivr_menu_options', ['ivr_menu_id' => $menu->id, 'input_digits' => '3', 'destination_type' => 'unavailable']);
    }

    public function test_ivr_menu_with_only_an_unavailable_option_is_unprovisioned(): void
    {
        $ext = Extension::factory()->create($this->o());
        $menu = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'extension', 'failover_destination_id' => $ext->id]);
        DB::table('ivr_menu_options')->insert(['ivr_menu_id' => $menu->id, 'input_digits' => '1', 'destination_type' => 'unavailable', 'destination_id' => 0, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ready = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'extension', 'failover_destination_id' => $ext->id]);

        $this->getJson("/api/v1/ivr-menus/{$menu->id}")->assertJsonPath('data.is_unprovisioned', true);
        $this->getJson("/api/v1/ivr-menus/{$ready->id}")->assertJsonPath('data.is_unprovisioned', false);
    }

    // ---- DIDs ------------------------------------------------------------------------------------------------

    public function test_did_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $this->postJson('/api/v1/phone-numbers', [
            'phone_number' => '+12125559901', 'routing_type' => 'unavailable', 'routing_config' => [], 'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors(['routing_type']);

        $ext = Extension::factory()->create($this->o());
        $did = DidNumber::factory()->routeToExtension($ext)->create($this->o());

        // Switch connected -> unavailable, then re-save the unchanged unavailable route.
        $this->putJson("/api/v1/phone-numbers/{$did->id}", ['routing_type' => 'unavailable', 'routing_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
        $this->putJson("/api/v1/phone-numbers/{$did->id}", ['friendly_name' => 'Soon', 'routing_type' => 'unavailable', 'routing_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    // ---- Call tracking campaigns -----------------------------------------------------------------------------

    public function test_call_tracking_campaign_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $this->postJson('/api/v1/call-tracking-campaigns', [
            'name' => 'Later', 'destination_type' => 'unavailable', 'destination_config' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['destination_type']);

        $ext = Extension::factory()->create($this->o());
        $campaign = CallTrackingCampaign::factory()->toExtension($ext->id)->create($this->o());

        $this->putJson("/api/v1/call-tracking-campaigns/{$campaign->id}", ['name' => 'Later 2', 'destination_type' => 'unavailable', 'destination_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
        $this->putJson("/api/v1/call-tracking-campaigns/{$campaign->id}", ['name' => 'Later 3', 'destination_type' => 'unavailable', 'destination_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    // ---- Ring groups / queues / AI load balancers / business hours --------------------------------------------

    public function test_ring_group_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $member = Extension::factory()->create($this->o());

        $this->postJson('/api/v1/ring-groups', $this->ringGroupPayload('unavailable', $member->id))
            ->assertUnprocessable()->assertJsonValidationErrors(['fallback_action']);

        $id = $this->postJson('/api/v1/ring-groups', $this->ringGroupPayload('hangup', $member->id))->assertCreated()->json('data.id');
        $this->putJson("/api/v1/ring-groups/{$id}", $this->ringGroupPayload('unavailable', $member->id, ['name' => 'RG']))
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
        $this->putJson("/api/v1/ring-groups/{$id}", $this->ringGroupPayload('unavailable', $member->id, ['name' => 'RG2']))
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_call_queue_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $agent = User::factory()->create($this->o());
        Extension::factory()->create($this->o() + ['user_id' => $agent->id]);
        $queue = CallQueue::factory()->create($this->o() + ['fallback_action' => 'hangup']);
        $payload = fn (string $fallback) => ['name' => 'Q'.$fallback, 'strategy' => 'ring_all', 'status' => 'active', 'fallback_action' => $fallback, 'agents' => [$agent->id]];

        $this->postJson('/api/v1/call-queues', $payload('unavailable') + ['max_wait_seconds' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors(['fallback_action']);

        $this->putJson("/api/v1/call-queues/{$queue->id}", $payload('unavailable') + ['max_wait_seconds' => 60])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_albs_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $this->postJson('/api/v1/ai-assistant-load-balancers', [
            'name' => 'LB', 'strategy' => 'round_robin', 'status' => 'active', 'fallback_action' => 'unavailable',
        ])->assertUnprocessable()->assertJsonValidationErrors(['fallback_action']);

        $lb = AiAssistantLoadBalancer::factory()->create($this->o() + ['fallback_action' => 'hangup']);
        $assistant = AiAssistant::factory()->create($this->o());
        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", ['fallback_action' => 'unavailable', 'members' => [['ai_assistant_id' => $assistant->id, 'priority' => 1]]])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_business_hours_create_rejects_unavailable_and_update_accepts_it(): void
    {
        $this->postJson('/api/v1/business-hours', $this->hoursPayload('unavailable', 'hangup'))
            ->assertUnprocessable()->assertJsonValidationErrors(['open_hours_action.type']);
        $this->postJson('/api/v1/business-hours', $this->hoursPayload('hangup', 'unavailable'))
            ->assertUnprocessable()->assertJsonValidationErrors(['closed_hours_action.type']);

        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $this->putJson("/api/v1/business-hours/{$hours->id}", $this->hoursPayload('unavailable', 'unavailable'))
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
        $this->putJson("/api/v1/business-hours/{$hours->id}", ['name' => 'Again'] + $this->hoursPayload('unavailable', 'unavailable'))
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    /** One unprovisioned + one ready + one in another tenant per type; the filter returns only the first. */
    public function test_unprovisioned_filter_per_resource_type_respects_tenant_scope(): void
    {
        $other = Organization::factory()->create();
        $ext = Extension::factory()->create($this->o());

        $cases = [];

        $bad = RingGroup::factory()->create($this->o() + ['fallback_action' => 'unavailable']);
        RingGroup::factory()->create($this->o() + ['fallback_action' => 'hangup']);
        RingGroup::factory()->create($this->o($other) + ['fallback_action' => 'unavailable']);
        $cases['ring-groups'] = $bad->id;

        $bad = CallQueue::factory()->create($this->o() + ['fallback_action' => 'unavailable']);
        CallQueue::factory()->create($this->o() + ['fallback_action' => 'hangup']);
        CallQueue::factory()->create($this->o($other) + ['fallback_action' => 'unavailable']);
        $cases['call-queues'] = $bad->id;

        $bad = AiAssistantLoadBalancer::factory()->create($this->o() + ['fallback_action' => 'unavailable']);
        AiAssistantLoadBalancer::factory()->create($this->o() + ['fallback_action' => 'hangup']);
        AiAssistantLoadBalancer::factory()->create($this->o($other) + ['fallback_action' => 'unavailable']);
        $cases['ai-assistant-load-balancers'] = $bad->id;

        $bad = BusinessHoursSchedule::factory()->create($this->o());
        $bad->update(['closed_hours_action_type' => 'unavailable', 'closed_hours_action' => ['target_id' => 'unavailable']]);
        BusinessHoursSchedule::factory()->create($this->o());
        $otherBh = BusinessHoursSchedule::factory()->create($this->o($other));
        $otherBh->update(['open_hours_action_type' => 'unavailable', 'open_hours_action' => ['target_id' => 'unavailable']]);
        $cases['business-hours'] = $bad->id;

        $bad = CallTrackingCampaign::factory()->create($this->o() + ['destination_type' => 'unavailable', 'destination_config' => []]);
        CallTrackingCampaign::factory()->toExtension($ext->id)->create($this->o());
        CallTrackingCampaign::factory()->create($this->o($other) + ['destination_type' => 'unavailable', 'destination_config' => []]);
        $cases['call-tracking-campaigns'] = $bad->id;

        $bad = DidNumber::factory()->create($this->o() + ['routing_type' => 'unavailable', 'routing_config' => []]);
        DidNumber::factory()->routeToExtension($ext)->create($this->o());
        DidNumber::factory()->create($this->o($other) + ['routing_type' => 'unavailable', 'routing_config' => []]);
        $cases['phone-numbers'] = $bad->id;

        // IVR: unprovisioned by failover, by an option, and a ready one; plus another tenant.
        $byFailover = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'unavailable']);
        $byOption = IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'hangup']);
        DB::table('ivr_menu_options')->insert(['ivr_menu_id' => $byOption->id, 'input_digits' => '1', 'destination_type' => 'unavailable', 'destination_id' => 0, 'priority' => 1, 'created_at' => now(), 'updated_at' => now()]);
        IvrMenu::factory()->create($this->o() + ['failover_destination_type' => 'hangup']);
        IvrMenu::factory()->create($this->o($other) + ['failover_destination_type' => 'unavailable']);

        foreach ($cases as $path => $expectedId) {
            $ids = collect($this->getJson("/api/v1/{$path}?unprovisioned=1&per_page=50")->assertOk()->json('data'))->pluck('id')->all();
            $this->assertEquals([$expectedId], $ids, $path);
            foreach ($this->getJson("/api/v1/{$path}?per_page=50")->json('data') as $row) {
                $this->assertSame((string) $row['id'] === (string) $expectedId, $row['is_unprovisioned'], "{$path} #{$row['id']}");
            }
        }

        $ids = collect($this->getJson('/api/v1/ivr-menus?unprovisioned=1&per_page=50')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$byFailover->id, $byOption->id], $ids);
        $flags = collect($this->getJson('/api/v1/ivr-menus?per_page=50')->json('data'))->pluck('is_unprovisioned', 'id');
        $this->assertTrue($flags[$byOption->id]);
        $this->assertCount(3, $flags);
        $this->assertSame(2, $flags->filter()->count());
    }
}
