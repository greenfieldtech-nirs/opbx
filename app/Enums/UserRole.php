<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case OWNER = 'owner';
    case PBX_ADMIN = 'pbx_admin';
    case PBX_USER = 'pbx_user';
    case REPORTER = 'reporter';
    case SUPERVISOR = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::PBX_ADMIN => 'PBX Admin',
            self::PBX_USER => 'PBX User',
            self::REPORTER => 'Reporter',
            self::SUPERVISOR => 'Supervisor',
        };
    }

    /**
     * Sanctum token abilities for this role (single source for password login,
     * Auth0 login and registration). Platform managers also get the platform:* set.
     *
     * @return array<int, string>
     */
    public function tokenAbilities(bool $isPlatformManager = false): array
    {
        $abilities = match ($this) {
            self::OWNER => [
                'extension:*',
                'user:*',
                'ring-group:*',
                'did-number:*',
                'recording:*',
                'settings:*',
                'business-hours:*',
                'conference:*',
                'ivr:*',
                'call-log:*',
                'outbound-whitelist:*',
                'recording-download:*',
            ],
            self::PBX_ADMIN => [
                'extension:*',
                'user:read',
                'user:update',
                'ring-group:*',
                'did-number:*',
                'recording:read',
                'business-hours:*',
                'conference:*',
                'ivr:*',
                'call-log:*',
            ],
            self::PBX_USER => [
                'extension:read',
                'extension:update:own',
                'user:read',
                'ring-group:read',
                'did-number:read',
                'recording:read',
                'call-log:read',
            ],
            self::REPORTER => [
                'extension:read',
                'user:read',
                'ring-group:read',
                'did-number:read',
                'recording:read',
                'call-log:read',
                'business-hours:read',
            ],
            self::SUPERVISOR => [
                'extension:read',
                'user:read',
                'ring-group:read',
                'did-number:read',
                'recording:read',
                'call-log:read',
                'business-hours:read',
                'supervisor:view',
                'supervisor:assignments',
            ],
        };

        if ($isPlatformManager) {
            $abilities = array_merge($abilities, [
                'platform:read',
                'platform:write',
                'platform:manage-users',
                'platform:manage-organizations',
                'platform:audit-logs',
            ]);
        }

        return $abilities;
    }

    public function canManageOrganization(): bool
    {
        return $this === self::OWNER;
    }

    public function canManageUsers(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN], true);
    }

    public function canManageConfiguration(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN], true);
    }

    public function canViewReports(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN, self::REPORTER], true);
    }

    public function canManageOwnData(): bool
    {
        return true;
    }

    public function isOwner(): bool
    {
        return $this === self::OWNER;
    }

    public function isPBXAdmin(): bool
    {
        return $this === self::PBX_ADMIN;
    }

    public function isPBXUser(): bool
    {
        return $this === self::PBX_USER;
    }

    public function isReporter(): bool
    {
        return $this === self::REPORTER;
    }

    public function isSupervisor(): bool
    {
        return $this === self::SUPERVISOR;
    }

    public function canViewAssignedDashboard(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN, self::SUPERVISOR], true);
    }

    public function canViewLiveCalls(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN, self::SUPERVISOR], true);
    }

    public function canViewAssignedReports(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN, self::SUPERVISOR, self::REPORTER], true);
    }

    public function canAssignSupervisors(): bool
    {
        return in_array($this, [self::OWNER, self::PBX_ADMIN], true);
    }
}
