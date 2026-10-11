<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = $this->makeUser(UserRole::OWNER);
    }

    private function makeUser(UserRole $role): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => $role,
            'password' => Hash::make('OldPassword123!'),
        ]);
    }

    public function test_pbx_user_cannot_self_promote_or_reparent(): void
    {
        $user = $this->makeUser(UserRole::PBX_USER);
        $other = Organization::factory()->create();
        $before = $user->fresh()->getAttributes();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'is_platform_manager' => true,
            'role' => 'owner',
            'organization_id' => $other->id,
        ]);

        $this->assertContains($response->status(), [403, 422]);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_pbx_user_self_update_ignores_privileged_fields(): void
    {
        $user = $this->makeUser(UserRole::PBX_USER);
        $other = Organization::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Renamed Person',
            'is_platform_manager' => true,
            'organization_id' => $other->id,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Renamed Person', $fresh->name);
        $this->assertFalse($fresh->is_platform_manager);
        $this->assertSame($this->org->id, $fresh->organization_id);
    }

    public function test_pbx_admin_cannot_change_owner_role_or_password(): void
    {
        $admin = $this->makeUser(UserRole::PBX_ADMIN);
        $hash = $this->owner->fresh()->password;

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$this->owner->id}", [
            'role' => 'reporter',
        ])->assertStatus(403);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$this->owner->id}", [
            'password' => 'NewPassword123!',
        ])->assertStatus(403);

        $fresh = $this->owner->fresh();
        $this->assertSame(UserRole::OWNER, $fresh->role);
        $this->assertSame($hash, $fresh->password);
    }

    public function test_store_ignores_is_platform_manager(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'NewPassword123!',
            'role' => 'pbx_user',
            'is_platform_manager' => true,
        ])->assertStatus(201);

        $created = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertFalse($created->is_platform_manager);
    }

    public function test_owner_can_change_role(): void
    {
        $user = $this->makeUser(UserRole::PBX_USER);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'role' => 'reporter',
        ])->assertOk();

        $this->assertSame(UserRole::REPORTER, $user->fresh()->role);
    }

    public function test_pbx_admin_can_deactivate_user_but_not_owner(): void
    {
        $admin = $this->makeUser(UserRole::PBX_ADMIN);
        $user = $this->makeUser(UserRole::PBX_USER);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'status' => 'inactive',
        ])->assertOk();
        $this->assertSame(UserStatus::INACTIVE, $user->fresh()->status);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$this->owner->id}", [
            'status' => 'inactive',
        ])->assertForbidden();
        $this->assertSame(UserStatus::ACTIVE, $this->owner->fresh()->status);
    }

    public function test_pbx_user_cannot_change_own_status(): void
    {
        $user = $this->makeUser(UserRole::PBX_USER);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'status' => 'inactive',
        ])->assertForbidden();
    }

    public function test_owner_cannot_change_organization_id(): void
    {
        $user = $this->makeUser(UserRole::PBX_USER);
        $other = Organization::factory()->create();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Still Here',
            'organization_id' => $other->id,
        ])->assertOk();

        $this->assertSame($this->org->id, $user->fresh()->organization_id);
    }

    public function test_deleting_last_owner_is_blocked_by_design(): void
    {
        $admin = $this->makeUser(UserRole::PBX_ADMIN);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$this->owner->id}");

        $this->assertContains($response->status(), [403, 409]);
        $this->assertNotNull($this->owner->fresh());
    }
}
