<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorCallLogsShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_view_assigned_cdr(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'type' => 'user',
            'extension_number' => '1001',
        ]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $cdr = CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '1001',
            'to' => '5550100',
        ]);

        $this->actingAs($supervisor)
            ->getJson("/api/v1/call-detail-records/{$cdr->id}")
            ->assertOk()
            ->assertJsonPath('data.from', '1001');
    }

    public function test_supervisor_cannot_view_unassigned_cdr(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $assignedUser->id,
            'type' => 'user',
            'extension_number' => '1001',
        ]);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $cdr = CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
            'to' => '5550101',
        ]);

        $this->actingAs($supervisor)
            ->getJson("/api/v1/call-detail-records/{$cdr->id}")
            ->assertForbidden();
    }
}
