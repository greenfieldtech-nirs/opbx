<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    public function test_supervisor_label(): void
    {
        $this->assertSame('Supervisor', UserRole::SUPERVISOR->label());
    }

    public function test_supervisor_can_view_assigned_dashboard(): void
    {
        $this->assertTrue(UserRole::SUPERVISOR->canViewAssignedDashboard());
        $this->assertFalse(UserRole::PBX_USER->canViewAssignedDashboard());
        $this->assertFalse(UserRole::REPORTER->canViewAssignedDashboard());
    }

    public function test_supervisor_can_view_live_calls(): void
    {
        $this->assertTrue(UserRole::SUPERVISOR->canViewLiveCalls());
    }

    public function test_supervisor_can_view_assigned_reports(): void
    {
        $this->assertTrue(UserRole::SUPERVISOR->canViewAssignedReports());
    }

    public function test_supervisor_cannot_assign_supervisors(): void
    {
        $this->assertFalse(UserRole::SUPERVISOR->canAssignSupervisors());
        $this->assertTrue(UserRole::OWNER->canAssignSupervisors());
        $this->assertTrue(UserRole::PBX_ADMIN->canAssignSupervisors());
    }
}
