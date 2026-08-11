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

    /**
     * A single call produces several session_updates rows as it transitions
     * through statuses (ringing -> connected -> answer, etc.) - the active
     * call count must reflect distinct sessions, not raw rows.
     */
    public function test_active_calls_count_reflects_distinct_sessions_not_raw_rows(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'extension_number' => '1006',
        ]);

        foreach (['ringing', 'connected', 'answer'] as $status) {
            SessionUpdate::factory()->create([
                'organization_id' => $org->id,
                'session_id' => 700001,
                'caller_id' => '+1006',
                'destination' => '+60002',
                'status' => $status,
                'action' => 'updated',
            ]);
        }

        // A second, already-completed call from the same extension must not
        // be counted as active even though a stale row still matches status.
        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'session_id' => 700002,
            'caller_id' => '+1006',
            'destination' => '+60002',
            'status' => 'ANSWER',
            'action' => 'cdr_final_status',
        ]);
        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'session_id' => 700002,
            'caller_id' => '+1006',
            'destination' => '+60002',
            'status' => 'connected',
            'action' => 'updated',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/dashboard/supervisor');

        $response->assertOk()
            ->assertJsonPath('active_calls_count', 1);
    }

    public function test_supervisor_with_no_assignments_sees_zero_active_calls_and_no_recent_calls(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);

        SessionUpdate::factory()->create([
            'organization_id' => $org->id,
            'caller_id' => '+9999',
            'status' => 'connected',
        ]);
        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/dashboard/supervisor');

        $response->assertOk()
            ->assertJsonPath('active_calls_count', 0)
            ->assertJsonPath('recent_calls', fn ($recentCalls) => count($recentCalls) === 0);
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
