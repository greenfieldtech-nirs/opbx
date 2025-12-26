<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Role enum test suite.
 *
 * Tests the UserRole enum permission methods without database dependencies.
 */
class RoleEnumTest extends TestCase
{
    public function test_owner_role_has_correct_permissions(): void
    {
        $role = UserRole::OWNER;

        $this->assertTrue($role->canManageOrganization());
        $this->assertTrue($role->canManageUsers());
        $this->assertTrue($role->canManageConfiguration());
        $this->assertTrue($role->canViewReports());
        $this->assertTrue($role->canManageOwnData());
        $this->assertTrue($role->isOwner());
        $this->assertFalse($role->isPBXAdmin());
        $this->assertFalse($role->isPBXUser());
        $this->assertFalse($role->isReporter());
    }

    public function test_pbx_admin_role_has_correct_permissions(): void
    {
        $role = UserRole::PBX_ADMIN;

        $this->assertFalse($role->canManageOrganization());
        $this->assertTrue($role->canManageUsers());
        $this->assertTrue($role->canManageConfiguration());
        $this->assertTrue($role->canViewReports());
        $this->assertTrue($role->canManageOwnData());
        $this->assertFalse($role->isOwner());
        $this->assertTrue($role->isPBXAdmin());
        $this->assertFalse($role->isPBXUser());
        $this->assertFalse($role->isReporter());
    }

    public function test_pbx_user_role_has_correct_permissions(): void
    {
        $role = UserRole::PBX_USER;

        $this->assertFalse($role->canManageOrganization());
        $this->assertFalse($role->canManageUsers());
        $this->assertFalse($role->canManageConfiguration());
        $this->assertFalse($role->canViewReports());
        $this->assertTrue($role->canManageOwnData());
        $this->assertFalse($role->isOwner());
        $this->assertFalse($role->isPBXAdmin());
        $this->assertTrue($role->isPBXUser());
        $this->assertFalse($role->isReporter());
    }

    public function test_reporter_role_has_correct_permissions(): void
    {
        $role = UserRole::REPORTER;

        $this->assertFalse($role->canManageOrganization());
        $this->assertFalse($role->canManageUsers());
        $this->assertFalse($role->canManageConfiguration());
        $this->assertTrue($role->canViewReports());
        $this->assertTrue($role->canManageOwnData());
        $this->assertFalse($role->isOwner());
        $this->assertFalse($role->isPBXAdmin());
        $this->assertFalse($role->isPBXUser());
        $this->assertTrue($role->isReporter());
    }

    public function test_role_labels_are_correct(): void
    {
        $this->assertEquals('Owner', UserRole::OWNER->label());
        $this->assertEquals('PBX Admin', UserRole::PBX_ADMIN->label());
        $this->assertEquals('PBX User', UserRole::PBX_USER->label());
        $this->assertEquals('Reporter', UserRole::REPORTER->label());
    }

    public function test_role_values_are_correct(): void
    {
        $this->assertEquals('owner', UserRole::OWNER->value);
        $this->assertEquals('pbx_admin', UserRole::PBX_ADMIN->value);
        $this->assertEquals('pbx_user', UserRole::PBX_USER->value);
        $this->assertEquals('reporter', UserRole::REPORTER->value);
    }

    public function test_all_role_cases_exist(): void
    {
        $cases = UserRole::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(UserRole::OWNER, $cases);
        $this->assertContains(UserRole::PBX_ADMIN, $cases);
        $this->assertContains(UserRole::PBX_USER, $cases);
        $this->assertContains(UserRole::REPORTER, $cases);
    }
}
