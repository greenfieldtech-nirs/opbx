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
}
