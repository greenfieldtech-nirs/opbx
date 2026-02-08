<?php

declare(strict_types=1);

namespace App\Enums;

enum AlbsStatus: string
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

    public function description(): string
    {
        return match ($this) {
            self::ACTIVE => 'Load balancer is active and can distribute calls',
            self::INACTIVE => 'Load balancer is temporarily disabled',
        };
    }
}
