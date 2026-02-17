<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status enum for whitelist/blacklist entries and other toggleable entities.
 */
enum WhitelistStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
