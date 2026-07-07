<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User Policy Tests
 *
 * Tests all authorization rules in UserPolicy
 * Ensures role-based access control is working correctly
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $ownerUser;

    protected User $pbxAdminUser;

    protected User $pbxUser;

    protected User $reporterUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test organization
        $this->organization = Organization::factory()->create();

        // Create test users with different roles
        $this->ownerUser = User::factory()->create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        $this->pbxAdminUser = User::factory()->create([
            'name' => 'Test PBX Admin',
            'email' => 'admin@example.com',
            'role' => UserRole::PBX_ADMIN,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        $this->pbxUser = User::factory()->create([
            'name' => 'Test PBX User',
            'email' => 'pbx@example.com',
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        $this->reporterUser = User::factory()->create([
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'role' => UserRole::REPORTER,
            'status' => UserStatus::ACTIVE,
            'organization_id' => $this->organization->id,
        ]);

        $this->ownerUser->organization_id = $this->organization->id;
        $this->pbxAdminUser->organization_id = $this->organization->id;
        $this->pbxUser->organization_id = $this->organization->id;
        $this->reporterUser->organization_id = $this->organization->id;
    }

    public function test_view_any_with_owner(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->viewAny($this->ownerUser),
            'Owner should be able to view all users'
        );
    }

    public function test_view_any_with_pbx_admin(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->viewAny($this->pbxAdminUser),
            'PBX Admin should be able to view all users'
        );
    }

    public function test_view_any_with_pbx_user(): void
    {
        $policy = new UserPolicy;

        $this->assertFalse(
            $policy->viewAny($this->pbxUser),
            'PBX User should not be able to view all users'
        );
    }

    public function test_view_any_with_reporter(): void
    {
        $policy = new UserPolicy;

        $this->assertFalse(
            $policy->viewAny($this->reporterUser),
            'Reporter should not be able to view all users'
        );
    }

    public function test_create_user_with_owner(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->create($this->ownerUser),
            'Owner should be able to create users'
        );
    }

    public function test_create_user_with_pbx_admin(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->create($this->pbxAdminUser),
            'PBX Admin should be able to create users'
        );
    }

    public function test_view_user_with_owner_and_target_same_org(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->view($this->ownerUser, $this->ownerUser),
            'Owner can view user in same organization'
        );
    }

    public function test_view_user_with_reporter_and_different_org(): void
    {
        $policy = new UserPolicy;
        $otherOrganization = Organization::factory()->create();
        $otherOrgUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->assertFalse(
            $policy->view($this->reporterUser, $otherOrgUser),
            'Reporter cannot view user in different organization'
        );
    }

    public function test_view_user_with_pbx_user_and_different_org(): void
    {
        $policy = new UserPolicy;
        $otherOrganization = Organization::factory()->create();
        $otherOrgUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->assertFalse(
            $policy->view($this->pbxUser, $otherOrgUser),
            'PBX User cannot view user in different organization'
        );
    }

    public function test_view_user_with_pbx_admin_and_different_org(): void
    {
        $policy = new UserPolicy;
        $otherOrganization = Organization::factory()->create();
        $otherOrgUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->assertFalse(
            $policy->view($this->pbxAdminUser, $otherOrgUser),
            'PBX Admin cannot view user in different organization'
        );
    }

    public function test_pbx_user_can_only_view_themselves(): void
    {
        $policy = new UserPolicy;

        $this->assertTrue(
            $policy->view($this->pbxUser, $this->pbxUser),
            'PBX User can only view themselves'
        );
    }

    public function test_reporter_cannot_view_user_details(): void
    {
        $policy = new UserPolicy;

        $this->assertFalse(
            $policy->view($this->reporterUser, $this->pbxUser),
            'Reporter cannot view user details'
        );
    }
}
