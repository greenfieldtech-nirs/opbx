<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\SessionUpdate;
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

        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'extension_number' => '1001',
        ]);

        // Active call matching the assigned user's extension
        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '1001',
            'status' => 'connected',
            'session_modified_at' => now(),
        ]);

        // Recent CDR matching the assigned user's extension
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '1001',
        ]);

        // Non-matching active call should not be counted
        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '9999',
            'status' => 'connected',
            'session_modified_at' => now(),
        ]);

        // Non-matching CDR should not be counted
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/dashboard/supervisor');

        $response->assertOk()
            ->assertJsonPath('assigned_users_count', 1)
            ->assertJsonPath('assigned_users.0.id', $assignedUser->id)
            ->assertJsonPath('active_calls_count', 1)
            ->assertJsonPath('recent_calls', fn ($recentCalls) => count($recentCalls) === 1);
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
