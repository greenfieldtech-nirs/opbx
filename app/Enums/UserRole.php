<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case OWNER = 'owner';
    case PBX_ADMIN = 'pbx_admin';
    case PBX_USER = 'pbx_user';
    case REPORTER = 'reporter';

    public function label(): string
    {
        return match($this) {
            self::OWNER => 'Owner',
            self::PBX_ADMIN => 'PBX Admin',
            self::PBX_USER => 'PBX User',
            self::REPORTER => 'Reporter',
        };
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
}
