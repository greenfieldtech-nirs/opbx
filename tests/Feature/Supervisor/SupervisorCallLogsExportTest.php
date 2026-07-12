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

final class SupervisorCallLogsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_export_only_includes_assigned_cdrs(): void
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
        ]);

        CallDetailRecord::factory()->create([
            'organization_id' => $org->id,
            'from' => '9999',
            'to' => '5550101',
        ]);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/v1/call-detail-records/export');

        $response->assertOk();

        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();

        $lines = explode("\n", trim($content));

        // Header + one data row
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('1001', $lines[1]);
        $this->assertStringNotContainsString('9999', $content);
    }
}
