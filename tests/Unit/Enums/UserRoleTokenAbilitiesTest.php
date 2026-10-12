<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTokenAbilitiesTest extends TestCase
{
    public function test_every_role_has_abilities(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotEmpty($role->tokenAbilities(), $role->value);
        }
    }

    public function test_owner_has_full_resource_access(): void
    {
        $abilities = UserRole::OWNER->tokenAbilities();

        $this->assertContains('user:*', $abilities);
        $this->assertContains('settings:*', $abilities);
        $this->assertContains('recording-download:*', $abilities);
    }

    public function test_supervisor_keeps_supervisor_abilities(): void
    {
        $abilities = UserRole::SUPERVISOR->tokenAbilities();

        $this->assertContains('supervisor:view', $abilities);
        $this->assertContains('supervisor:assignments', $abilities);
    }

    public function test_platform_manager_gets_platform_abilities_only_when_flagged(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotContains('platform:read', $role->tokenAbilities());
            $this->assertSame(
                [...$role->tokenAbilities(), 'platform:read', 'platform:write', 'platform:manage-users', 'platform:manage-organizations', 'platform:audit-logs'],
                $role->tokenAbilities(true),
            );
        }
    }
}
