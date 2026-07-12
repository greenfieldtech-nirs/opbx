<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorUsersListTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_sees_only_self_and_assigned_users(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $unassignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertEquals(
            [$supervisor->id, $assignedUser->id],
            $ids,
            'Supervisor should see only themselves and assigned users'
        );
    }

    public function test_supervisor_does_not_see_unassigned_user(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $unassignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains(
            $unassignedUser->id,
            $ids,
            'Supervisor should not see unassigned users'
        );
    }

    public function test_owner_sees_all_users(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $unassignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(4, 'data');
    }

    public function test_pbx_admin_sees_all_users(): void
    {
        $org = Organization::factory()->create();
        $pbxAdmin = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_ADMIN]);
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $unassignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($pbxAdmin)
            ->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonCount(4, 'data');
    }
}
