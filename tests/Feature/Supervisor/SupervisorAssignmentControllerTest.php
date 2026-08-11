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
        $response->assertJsonPath('data.supervisor_id', $supervisor->id);
        $response->assertJsonPath('data.user_ids', [$user->id]);
        $response->assertJsonPath('data.ring_group_ids', [$ringGroup->id]);
        $this->assertTrue($supervisor->fresh()->supervisedUsers->contains($user));
        $this->assertTrue($supervisor->fresh()->supervisedRingGroups->contains($ringGroup));
    }

    public function test_owner_can_clear_all_assignments(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);
        $supervisor->supervisedRingGroups()->attach($ringGroup->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [],
                'ring_group_ids' => [],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.user_ids', []);
        $response->assertJsonPath('data.ring_group_ids', []);
        $this->assertCount(0, $supervisor->fresh()->supervisedUsers);
        $this->assertCount(0, $supervisor->fresh()->supervisedRingGroups);
    }

    public function test_owner_can_assign_only_users_with_no_ring_groups(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [$user->id],
                'ring_group_ids' => [],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.user_ids', [$user->id]);
        $response->assertJsonPath('data.ring_group_ids', []);
        $this->assertTrue($supervisor->fresh()->supervisedUsers->contains($user));
        $this->assertCount(0, $supervisor->fresh()->supervisedRingGroups);
    }

    public function test_owner_can_assign_only_ring_groups_with_no_users(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                'user_ids' => [],
                'ring_group_ids' => [$ringGroup->id],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.user_ids', []);
        $response->assertJsonPath('data.ring_group_ids', [$ringGroup->id]);
        $this->assertCount(0, $supervisor->fresh()->supervisedUsers);
        $this->assertTrue($supervisor->fresh()->supervisedRingGroups->contains($ringGroup));
    }

    public function test_missing_assignment_keys_are_still_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);

        $response = $this->actingAs($owner)
            ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['user_ids', 'ring_group_ids']);
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

    /**
     * Only PBX Users may be supervised; owner, pbx_admin, reporter, and supervisor
     * roles are all rejected.
     */
    public function test_cannot_assign_non_pbx_user_role_as_supervised_user(): void
    {
        foreach ([UserRole::OWNER, UserRole::PBX_ADMIN, UserRole::REPORTER] as $role) {
            $org = Organization::factory()->create();
            $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
            $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
            $target = User::factory()->create(['organization_id' => $org->id, 'role' => $role]);

            $response = $this->actingAs($owner)
                ->putJson("/api/v1/supervisors/{$supervisor->id}/assignments", [
                    'user_ids' => [$target->id],
                    'ring_group_ids' => [],
                ]);

            $response->assertUnprocessable();
            $this->assertFalse(
                $supervisor->fresh()->supervisedUsers->contains($target),
                "Role {$role->value} should not be assignable as a supervised user."
            );
        }
    }
}
