<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserSupervisorTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_have_assigned_users(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);

        $this->assertTrue($supervisor->supervisedUsers->contains($user));
        $this->assertTrue($user->supervisingSupervisors->contains($supervisor));
    }

    public function test_supervisor_can_have_assigned_ring_groups(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);

        $supervisor->supervisedRingGroups()->attach($ringGroup->id, ['organization_id' => $org->id]);

        $this->assertTrue($supervisor->supervisedRingGroups->contains($ringGroup));
    }

    public function test_is_supervisor_helper(): void
    {
        $supervisor = User::factory()->make(['role' => UserRole::SUPERVISOR]);
        $admin = User::factory()->make(['role' => UserRole::PBX_ADMIN]);

        $this->assertTrue($supervisor->isSupervisor());
        $this->assertFalse($admin->isSupervisor());
    }

    public function test_supervisor_cannot_be_supervised(): void
    {
        $supervisor = User::factory()->make(['role' => UserRole::SUPERVISOR]);
        $this->assertFalse($supervisor->isAssignableAsSupervisor());
    }
}
