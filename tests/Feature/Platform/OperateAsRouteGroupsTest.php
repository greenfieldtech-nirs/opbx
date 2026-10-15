<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OperateAsRouteGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_impersonating_manager_reads_target_org_session_updates(): void
    {
        $platformOrg = Organization::factory()->create();
        $target = Organization::factory()->create();
        SessionUpdate::factory()->create(['organization_id' => $target->id, 'status' => 'connected']);

        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $platformOrg->id,
            'role' => UserRole::OWNER,
            'is_platform_manager' => true,
        ]));

        $this->withHeaders(['X-Operate-As-Organization' => (string) $target->id])
            ->getJson('/api/v1/session-updates/active/stats')
            ->assertOk()
            ->assertJsonPath('data.total_active', 1);
    }
}
