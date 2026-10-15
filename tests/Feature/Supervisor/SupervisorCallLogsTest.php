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

final class SupervisorCallLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_only_sees_assigned_cdr(): void
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

        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '1001',
        ]);

        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/call-detail-records?supervisor=true');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_supervisor_is_scoped_without_the_flag_and_sees_own_calls(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $assignedUser->id, 'type' => 'user', 'extension_number' => '1001']);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $supervisor->id, 'type' => 'user', 'extension_number' => '1002']);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        CallDetailRecord::factory()->create(['organization_id' => $org->id, 'from' => '1001']);
        CallDetailRecord::factory()->create(['organization_id' => $org->id, 'from' => '1002']);
        CallDetailRecord::factory()->create(['organization_id' => $org->id, 'from' => '9999']);

        $this->actingAs($supervisor)->getJson('/api/v1/call-detail-records')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($supervisor)->getJson('/api/v1/call-detail-records/statistics')
            ->assertOk()->assertJsonPath('total_calls', 2);

        $owner = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
        $this->actingAs($owner)->getJson('/api/v1/call-detail-records')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_supervisor_sees_an_assigned_users_call_by_its_extension_whatever_the_caller_id(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $assignedUser = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $assignedUser->id, 'type' => 'user', 'extension_number' => '1001']);
        $supervisor->supervisedUsers()->attach($assignedUser->id, ['organization_id' => $org->id]);

        $didCallerId = CallDetailRecord::factory()->create(['organization_id' => $org->id, 'extension_number' => '1001', 'from' => '+12025550100', 'to' => '+15551234567']);
        CallDetailRecord::factory()->create(['organization_id' => $org->id, 'extension_number' => '9999', 'from' => '+12025550100', 'to' => '+15551234567']);

        $this->actingAs($supervisor)->getJson('/api/v1/call-detail-records')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $didCallerId->id);
        $this->actingAs($supervisor)->getJson("/api/v1/call-detail-records/{$didCallerId->id}")->assertOk();
    }
}
