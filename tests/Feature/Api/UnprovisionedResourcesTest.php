<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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

    public function test_ivr_menu_saves_with_unavailable_options_and_failover(): void
    {
        $id = $this->postJson('/api/v1/ivr-menus', $this->ivrPayload())->assertCreated()
            ->assertJsonPath('data.is_unprovisioned', true)->json('data.id');

        $this->assertDatabaseHas('ivr_menu_options', ['ivr_menu_id' => $id, 'destination_type' => 'unavailable', 'destination_id' => 0]);

        // Edit-and-save the unchanged route (the SPA sends the stale id back).
        $this->putJson("/api/v1/ivr-menus/{$id}", $this->ivrPayload(['name' => 'Renamed', 'options' => [
            ['input_digits' => '1', 'destination_type' => 'unavailable', 'destination_id' => 0, 'priority' => 1],
        ]]))->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_ivr_menu_with_only_an_unavailable_option_is_unprovisioned(): void
    {
        $ext = Extension::factory()->create($this->o());
        $this->postJson('/api/v1/ivr-menus', $this->ivrPayload([
            'failover_destination_type' => 'extension', 'failover_destination_id' => $ext->id,
            'options' => [['input_digits' => '1', 'destination_type' => 'unavailable', 'priority' => 1]],
        ]))->assertCreated()->assertJsonPath('data.is_unprovisioned', true);

        $this->postJson('/api/v1/ivr-menus', $this->ivrPayload([
            'name' => 'Ready', 'failover_destination_type' => 'extension', 'failover_destination_id' => $ext->id,
            'options' => [['input_digits' => '1', 'destination_type' => 'extension', 'destination_id' => $ext->id, 'priority' => 1]],
        ]))->assertCreated()->assertJsonPath('data.is_unprovisioned', false);
    }

    public function test_did_saves_with_unavailable_routing_create_and_update(): void
    {
        $id = $this->postJson('/api/v1/phone-numbers', [
            'phone_number' => '+12125559901', 'routing_type' => 'unavailable', 'routing_config' => [], 'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.is_unprovisioned', true)->json('data.id');

        $this->putJson("/api/v1/phone-numbers/{$id}", ['friendly_name' => 'Soon', 'routing_type' => 'unavailable', 'routing_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_call_tracking_campaign_saves_with_unavailable_destination_create_and_update(): void
    {
        $id = $this->postJson('/api/v1/call-tracking-campaigns', [
            'name' => 'Later', 'destination_type' => 'unavailable', 'destination_config' => [],
        ])->assertCreated()->assertJsonPath('data.is_unprovisioned', true)->json('data.id');

        $this->putJson("/api/v1/call-tracking-campaigns/{$id}", ['name' => 'Later 2', 'destination_type' => 'unavailable', 'destination_config' => []])
            ->assertOk()->assertJsonPath('data.is_unprovisioned', true);
    }

    public function test_ring_group_queue_albs_and_business_hours_accept_unavailable(): void
    {
        $member = Extension::factory()->create($this->o());
        $this->postJson('/api/v1/ring-groups', [
            'name' => 'RG', 'strategy' => 'simultaneous', 'timeout' => 30, 'ring_turns' => 1, 'status' => 'active',
            'fallback_action' => 'unavailable', 'members' => [['extension_id' => $member->id, 'priority' => 1]],
        ])->assertCreated()->assertJsonPath('data.is_unprovisioned', true);

        $hours = BusinessHoursSchedule::factory()->create($this->o());
        $day = ['enabled' => true, 'time_ranges' => [['start_time' => '09:00', 'end_time' => '17:00']]];
        $payload = [
            'name' => 'Renamed', 'status' => 'active', 'timezone' => 'UTC',
            'open_hours_action' => ['type' => 'unavailable', 'target_id' => 'unavailable'],
            'closed_hours_action' => ['type' => 'unavailable', 'target_id' => 'unavailable'],
            'schedule' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], $day),
        ];
        $this->putJson("/api/v1/business-hours/{$hours->id}", $payload)->assertOk()->assertJsonPath('data.is_unprovisioned', true);
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
