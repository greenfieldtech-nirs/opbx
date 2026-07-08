<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_assign_users_and_ring_groups_to_supervisor(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [$user->id],
                'ring_group_ids' => [$ringGroup->id],
            ]);

        $response->assertOk();
        $this->assertTrue($supervisor->fresh()->supervisedUsers->contains($user));
        $this->assertTrue($supervisor->fresh()->supervisedRingGroups->contains($ringGroup));
    }

    public function test_pbx_user_cannot_assign(): void
    {
        $org = Organization::factory()->create();
        $pbxUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $target = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $response = $this->actingAs($pbxUser)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [$target->id],
                'ring_group_ids' => [],
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_assign_supervisor_to_supervisor(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $otherSupervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [$otherSupervisor->id],
                'ring_group_ids' => [],
            ]);

        $response->assertUnprocessable();
    }
}
