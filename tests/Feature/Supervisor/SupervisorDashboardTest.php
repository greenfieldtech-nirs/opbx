<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_sees_only_assigned_resources(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $unassignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/dashboard/supervisor');

        $response->assertOk()
            ->assertJsonPath('assigned_users_count', 1)
            ->assertJsonPath('assigned_users.0.id', $assignedUser->id);
    }

    public function test_pbx_user_cannot_access_supervisor_dashboard(): void
    {
        $org = Organization::factory()->create();
        $pbxUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $response = $this->actingAs($pbxUser)
            ->getJson('/api/v1/dashboard/supervisor');

        $response->assertForbidden();
    }
}
