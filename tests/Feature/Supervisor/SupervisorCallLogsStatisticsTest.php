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

final class SupervisorCallLogsStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_statistics_only_includes_assigned_cdrs(): void
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
            'to' => '5550100',
            'duration' => 60,
            'billsec' => 45,
            'sell_cost' => 1.50,
            'disposition' => 'CONNECTED',
        ]);

        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
            'to' => '5550101',
            'duration' => 120,
            'billsec' => 90,
            'sell_cost' => 3.00,
            'disposition' => 'NO_ANSWER',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/call-detail-records/statistics');

        $response->assertOk()->assertJson([
            'total_calls' => 1,
            'total_duration' => 60,
            'total_billsec' => 45,
            'total_cost' => 1.50,
            'by_disposition' => ['CONNECTED' => 1],
        ]);
    }
}
