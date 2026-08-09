<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorRingGroupIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_index_returns_only_assigned_ring_groups(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);

        $assigned = RingGroup::factory()->create(['organization_id' => $org->id, 'name' => 'Assigned Group']);
        RingGroup::factory()->create(['organization_id' => $org->id, 'name' => 'Unassigned Group']);

        $supervisor->supervisedRingGroups()->attach($assigned->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($supervisor)->getJson('/api/v1/ring-groups');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_supervisor_with_no_assignments_sees_empty_list(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        RingGroup::factory()->create(['organization_id' => $org->id]);

        $response = $this->actingAs($supervisor)->getJson('/api/v1/ring-groups');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_owner_index_is_not_supervisor_scoped(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        RingGroup::factory()->count(3)->create(['organization_id' => $org->id]);

        $response = $this->actingAs($owner)->getJson('/api/v1/ring-groups');

        $response->assertOk()->assertJsonCount(3, 'data');
    }
}
