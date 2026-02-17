<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status enum for inbound blacklist entries.
 */
enum InboundBlacklistStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Disabled',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
