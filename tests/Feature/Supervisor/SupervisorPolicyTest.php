<?php

declare(strict_types=1);

namespace Tests\Feature\Supervisor;

use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Policies\ExtensionPolicy;
use App\Policies\RingGroupPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_can_view_assigned_user(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);

        $policy = new UserPolicy;
        $this->assertTrue($policy->view($supervisor, $user));
    }

    public function test_supervisor_cannot_view_unassigned_user(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $policy = new UserPolicy;
        $this->assertFalse($policy->view($supervisor, $user));
    }

    public function test_supervisor_cannot_manage_users(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);

        $policy = new UserPolicy;
        $this->assertFalse($policy->create($supervisor));
        $this->assertFalse($policy->update($supervisor, $user));
    }

    public function test_supervisor_can_view_assigned_ring_group(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);
        $supervisor->supervisedRingGroups()->attach($ringGroup->id, ['organization_id' => $org->id]);

        $policy = new RingGroupPolicy;
        $this->assertTrue($policy->view($supervisor, $ringGroup));
    }

    public function test_supervisor_can_view_assigned_users_extension(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        $extension = Extension::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'type' => 'user']);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);

        $policy = new ExtensionPolicy;
        $this->assertTrue($policy->view($supervisor, $extension));
    }

    public function test_supervisor_cannot_view_extension_from_another_organization(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => UserRole::PBX_USER]);
        $extension = Extension::factory()->create(['organization_id' => $otherOrg->id, 'user_id' => $user->id, 'type' => 'user']);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $otherOrg->id]);

        $policy = new ExtensionPolicy;
        $this->assertFalse($policy->view($supervisor, $extension));
    }

    public function test_supervisor_cannot_view_ring_group_from_another_organization(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $otherOrg->id]);
        $supervisor->supervisedRingGroups()->attach($ringGroup->id, ['organization_id' => $otherOrg->id]);

        $policy = new RingGroupPolicy;
        $this->assertFalse($policy->view($supervisor, $ringGroup));
    }
}
