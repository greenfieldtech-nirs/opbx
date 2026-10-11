<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrudTenantReparentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();

        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => UserRole::OWNER,
        ]));
    }

    private function ringGroupPayload(array $members, array $extra = []): array
    {
        return array_merge([
            'name' => 'RG Updated',
            'strategy' => 'simultaneous',
            'timeout' => 30,
            'ring_turns' => 2,
            'fallback_action' => 'hangup',
            'status' => 'active',
            'members' => $members,
        ], $extra);
    }

    public function test_ring_group_update_cannot_reparent_and_validates_members(): void
    {
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $own = Extension::factory()->create(['organization_id' => $this->org->id]);
        $foreign = Extension::factory()->create(['organization_id' => $this->other->id]);

        $this->putJson("/api/v1/ring-groups/{$group->id}", $this->ringGroupPayload(
            [['extension_id' => $own->id, 'priority' => 1]],
            ['organization_id' => $this->other->id]
        ))->assertOk();
        $this->assertSame($this->org->id, $group->fresh()->organization_id);

        $this->putJson("/api/v1/ring-groups/{$group->id}", $this->ringGroupPayload(
            [['extension_id' => $foreign->id, 'priority' => 1]]
        ))->assertStatus(422)->assertJsonValidationErrors(['members.0.extension_id']);

        $this->putJson("/api/v1/ring-groups/{$group->id}", ['timeout' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['timeout']);
    }

    public function test_ring_group_status_toggle_keeps_members(): void
    {
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $own = Extension::factory()->create(['organization_id' => $this->org->id]);
        $this->putJson("/api/v1/ring-groups/{$group->id}", $this->ringGroupPayload(
            [['extension_id' => $own->id, 'priority' => 1]]
        ))->assertOk();

        $this->putJson("/api/v1/ring-groups/{$group->id}", ['status' => 'inactive'])->assertOk();

        $this->assertSame(1, $group->members()->count());
    }

    public function test_conference_room_update_cannot_reparent_and_validates(): void
    {
        $room = ConferenceRoom::factory()->create(['organization_id' => $this->org->id]);

        $this->putJson("/api/v1/conference-rooms/{$room->id}", [
            'name' => 'Room Renamed',
            'organization_id' => $this->other->id,
        ])->assertOk();
        $this->assertSame($this->org->id, $room->fresh()->organization_id);

        $this->putJson("/api/v1/conference-rooms/{$room->id}", ['max_participants' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['max_participants']);
    }

    public function test_load_balancer_update_cannot_reparent_and_validates_members(): void
    {
        $lb = AiAssistantLoadBalancer::factory()->create(['organization_id' => $this->org->id]);
        $own = AiAssistant::factory()->create(['organization_id' => $this->org->id]);
        $foreign = AiAssistant::factory()->create(['organization_id' => $this->other->id]);

        $payload = fn (int|string $assistantId, array $extra = []) => array_merge([
            'name' => 'LB Updated',
            'strategy' => 'round_robin',
            'fallback_action' => 'hangup',
            'status' => 'active',
            'members' => [['ai_assistant_id' => $assistantId]],
        ], $extra);

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", $payload($own->id, ['organization_id' => $this->other->id]))
            ->assertOk();
        $this->assertSame($this->org->id, $lb->fresh()->organization_id);
        $this->assertSame(1, $lb->members()->count());

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", $payload($foreign->id))
            ->assertStatus(422)->assertJsonValidationErrors(['members.0.ai_assistant_id']);

        $this->putJson("/api/v1/ai-assistant-load-balancers/{$lb->id}", ['strategy' => 'bogus'])
            ->assertStatus(422)->assertJsonValidationErrors(['strategy']);
    }
}
