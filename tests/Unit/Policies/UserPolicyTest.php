<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Tests\TestCase;

/**
 * User Policy Tests
 *
 * Tests all authorization rules in UserPolicy
 * Ensures role-based access control is working correctly
 */
class UserPolicyTest extends TestCase
{
    protected User $ownerUser;

    protected User $pbxAdminUser;

    protected User $pbxUser;

    protected User $reporterUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users with different roles
        $this->ownerUser = User::factory()->create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'role' => \App\Enums\UserRole::OWNER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);

        $this->pbxAdminUser = User::factory()->create([
            'name' => 'Test PBX Admin',
            'email' => 'admin@example.com',
            'role' => \App\Enums\UserRole::PBX_ADMIN,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);

        $this->pbxUser = User::factory()->create([
            'name' => 'Test PBX User',
            'email' => 'pbx@example.com',
            'role' => \App\Enums\UserRole::PBX_USER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);

        $this->reporterUser = User::factory()->create([
            'name' => 'Test Reporter',
            'email' => 'reporter@example.com',
            'role' => \App\Enums\UserRole::REPORTER,
            'status' => \App\Enums\UserStatus::ACTIVE,
            'organization_id' => 1,
        ]);

        $this->ownerUser->organization_id = 1;
        $this->pbxAdminUser->organization_id = 1;
        $this->pbxUser->organization_id = 1;
        $this->reporterUser->organization_id = 1;
    }

    public function test_view_any_with_owner(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->viewAny($this->ownerUser),
            'Owner should be able to view all users'
        );
    }

    public function test_view_any_with_pbx_admin(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->viewAny($this->pbxAdminUser),
            'PBX Admin should be able to view all users'
        );
    }

    public function test_view_any_with_pbx_user(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->viewAny($this->pbxUser),
            'PBX User should be able to view all users'
        );
    }

    public function test_view_any_with_reporter(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->viewAny($this->reporterUser),
            'Reporter should be able to view all users'
        );
    }

    public function test_create_user_with_owner(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->create($this->ownerUser),
            'Owner should be able to create users'
        );
    }

    public function test_create_user_with_pbx_admin(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->create($this->pbxAdminUser),
            'PBX Admin should be able to create users'
        );
    }

    public function test_view_user_with_owner_and_target_same_org(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->view($this->ownerUser, $this->ownerUser),
            'Owner can view user in same organization'
        );
    }

    public function test_view_user_with_reporter_and_different_org(): void
    {
        $policy = new UserPolicy();

        $this->assertFalse(
            $policy->view($this->reporterUser, $this->ownerUser),
            'Reporter cannot view user in different organization'
        );
    }

    public function test_view_user_with_pbx_user_and_different_org(): void
    {
        $policy = new UserPolicy();

        $this->assertFalse(
            $policy->view($this->pbxUser, $this->ownerUser),
            'PBX User cannot view user in different organization'
        );
    }

    public function test_view_user_with_pbx_admin_and_different_org(): void
    {
        $policy = new UserPolicy();

        $this->assertFalse(
            $policy->view($this->pbxAdminUser, $this->ownerUser),
            'PBX Admin cannot view user in different organization'
        );
    }

    public function test_pbx_user_can_only_view_themselves(): void
    {
        $policy = new UserPolicy();

        $this->assertTrue(
            $policy->view($this->pbxUser, $this->pbxUser),
            'PBX User can only view themselves'
        );
    }

    public function test_reporter_cannot_view_user_details(): void
    {
        $policy = new UserPolicy();

        $this->assertFalse(
            $policy->view($this->reporterUser, $this->pbxUser),
            'Reporter cannot view user details'
        );
    }
}
