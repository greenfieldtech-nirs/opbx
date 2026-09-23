<?php

declare(strict_types=1);

namespace App\Enums;

enum CallQueueStatus: string
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
            self::ACTIVE => 'Queue is active and can receive calls',
            self::INACTIVE => 'Queue is temporarily disabled',
        };
    }
}
