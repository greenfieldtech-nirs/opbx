<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use App\Policies\OutboundWhitelistPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Unit tests for OutboundWhitelistPolicy.
 *
 * Tests authorization rules for outbound whitelist management operations
 * based on role-based access control.
 */
class OutboundWhitelistPolicyTest extends TestCase
{
    use RefreshDatabase;

    private OutboundWhitelistPolicy $policy;
    private Organization $organization;
    private Organization $otherOrganization;
    private OutboundWhitelist $outboundWhitelist;
    private OutboundWhitelist $otherOrgOutboundWhitelist;

    private User $owner;
    private User $pbxAdmin;
    private User $pbxUser;
    private User $reporter;
    private User $otherOrgUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new OutboundWhitelistPolicy();

        // Create mock users with different roles
        $this->owner = $this->createMockUser(UserRole::OWNER, 1);
        $this->pbxAdmin = $this->createMockUser(UserRole::PBX_ADMIN, 1);
        $this->pbxUser = $this->createMockUser(UserRole::PBX_USER, 1);
        $this->reporter = $this->createMockUser(UserRole::REPORTER, 1);
        $this->otherOrgUser = $this->createMockUser(UserRole::OWNER, 2);

        // Create mock outbound whitelists
        $this->outboundWhitelist = $this->createMockOutboundWhitelist(1);
        $this->otherOrgOutboundWhitelist = $this->createMockOutboundWhitelist(2);
    }

    /**
     * Create a mock user with specified role and organization ID.
     */
    private function createMockUser(UserRole $role, int $organizationId): User
    {
        $user = $this->createMock(User::class);
        $user->method('isOwner')->willReturn($role === UserRole::OWNER);
        $user->method('isPBXAdmin')->willReturn($role === UserRole::PBX_ADMIN);
        $user->organization_id = $organizationId;
        return $user;
    }

    /**
     * Create a mock outbound whitelist with specified organization ID.
     */
    private function createMockOutboundWhitelist(int $organizationId): OutboundWhitelist
    {
        $outboundWhitelist = $this->createMock(OutboundWhitelist::class);
        $outboundWhitelist->organization_id = $organizationId;
        return $outboundWhitelist;
    }

    /**
     * Test viewAny policy allows all authenticated users.
     */
    public function test_view_any_policy_allows_all_authenticated_users(): void
    {
        $this->assertTrue($this->policy->viewAny($this->owner));
        $this->assertTrue($this->policy->viewAny($this->pbxAdmin));
        $this->assertTrue($this->policy->viewAny($this->pbxUser));
        $this->assertTrue($this->policy->viewAny($this->reporter));
    }

    /**
     * Test view policy allows users to view outbound whitelists in their organization.
     */
    public function test_view_policy_allows_users_to_view_outbound_whitelists_in_their_organization(): void
    {
        // Users can view outbound whitelists in their own organization
        $this->assertTrue($this->policy->view($this->owner, $this->outboundWhitelist));
        $this->assertTrue($this->policy->view($this->pbxAdmin, $this->outboundWhitelist));
        $this->assertTrue($this->policy->view($this->pbxUser, $this->outboundWhitelist));
        $this->assertTrue($this->policy->view($this->reporter, $this->outboundWhitelist));
    }

    /**
     * Test view policy denies users from viewing outbound whitelists in other organizations.
     */
    public function test_view_policy_denies_users_from_viewing_outbound_whitelists_in_other_organizations(): void
    {
        // Users cannot view outbound whitelists from other organizations
        $this->assertFalse($this->policy->view($this->owner, $this->otherOrgOutboundWhitelist));
        $this->assertFalse($this->policy->view($this->pbxAdmin, $this->otherOrgOutboundWhitelist));
        $this->assertFalse($this->policy->view($this->pbxUser, $this->otherOrgOutboundWhitelist));
        $this->assertFalse($this->policy->view($this->reporter, $this->otherOrgOutboundWhitelist));
    }

    /**
     * Test create policy allows only owner and pbx admin roles.
     */
    public function test_create_policy_allows_only_owner_and_pbx_admin_roles(): void
    {
        // Owner and PBX Admin can create outbound whitelists
        $this->assertTrue($this->policy->create($this->owner));
        $this->assertTrue($this->policy->create($this->pbxAdmin));

        // PBX User and Reporter cannot create outbound whitelists
        $this->assertFalse($this->policy->create($this->pbxUser));
        $this->assertFalse($this->policy->create($this->reporter));
    }

    /**
     * Test update policy allows only owner and pbx admin roles within their organization.
     */
    public function test_update_policy_allows_only_owner_and_pbx_admin_roles_within_their_organization(): void
    {
        // Owner and PBX Admin can update outbound whitelists in their organization
        $this->assertTrue($this->policy->update($this->owner, $this->outboundWhitelist));
        $this->assertTrue($this->policy->update($this->pbxAdmin, $this->outboundWhitelist));

        // PBX User and Reporter cannot update outbound whitelists
        $this->assertFalse($this->policy->update($this->pbxUser, $this->outboundWhitelist));
        $this->assertFalse($this->policy->update($this->reporter, $this->outboundWhitelist));
    }

    /**
     * Test update policy denies cross-organization access.
     */
    public function test_update_policy_denies_cross_organization_access(): void
    {
        // Users cannot update outbound whitelists from other organizations, even if they have the right role
        $this->assertFalse($this->policy->update($this->owner, $this->otherOrgOutboundWhitelist));
        $this->assertFalse($this->policy->update($this->pbxAdmin, $this->otherOrgOutboundWhitelist));
    }

    /**
     * Test delete policy allows only owner and pbx admin roles within their organization.
     */
    public function test_delete_policy_allows_only_owner_and_pbx_admin_roles_within_their_organization(): void
    {
        // Owner and PBX Admin can delete outbound whitelists in their organization
        $this->assertTrue($this->policy->delete($this->owner, $this->outboundWhitelist));
        $this->assertTrue($this->policy->delete($this->pbxAdmin, $this->outboundWhitelist));

        // PBX User and Reporter cannot delete outbound whitelists
        $this->assertFalse($this->policy->delete($this->pbxUser, $this->outboundWhitelist));
        $this->assertFalse($this->policy->delete($this->reporter, $this->outboundWhitelist));
    }

    /**
     * Test delete policy denies cross-organization access.
     */
    public function test_delete_policy_denies_cross_organization_access(): void
    {
        // Users cannot delete outbound whitelists from other organizations, even if they have the right role
        $this->assertFalse($this->policy->delete($this->owner, $this->otherOrgOutboundWhitelist));
        $this->assertFalse($this->policy->delete($this->pbxAdmin, $this->otherOrgOutboundWhitelist));
    }

    /**
     * Test policy methods work correctly with inactive users.
     */
    public function test_policy_methods_work_correctly_with_inactive_users(): void
    {
        $inactiveUser = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
            'status' => UserStatus::INACTIVE,
        ]);

        // Even inactive owners should have the same permissions (status doesn't affect policy)
        // The policy doesn't check user status, only role and organization
        $this->assertTrue($this->policy->viewAny($inactiveUser));
        $this->assertTrue($this->policy->view($inactiveUser, $this->outboundWhitelist));
        $this->assertTrue($this->policy->create($inactiveUser));
        $this->assertTrue($this->policy->update($inactiveUser, $this->outboundWhitelist));
        $this->assertTrue($this->policy->delete($inactiveUser, $this->outboundWhitelist));
    }
}