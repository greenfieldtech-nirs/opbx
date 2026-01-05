<?php

declare(strict_types=1);

namespace App\Enums;

enum IvrMenuStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::ACTIVE => 'IVR menu is active and can receive calls',
            self::INACTIVE => 'IVR menu is temporarily disabled',
        };
    }
}