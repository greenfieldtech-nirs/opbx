<?php

declare(strict_types=1);

namespace App\Enums;

enum RingGroupStrategy: string
{
    case SIMULTANEOUS = 'simultaneous';
    case ROUND_ROBIN = 'round_robin';
    case SEQUENTIAL = 'sequential';

    public function label(): string
    {
        return match($this) {
            self::SIMULTANEOUS => 'Ring All (Simultaneous)',
            self::ROUND_ROBIN => 'Round Robin',
            self::SEQUENTIAL => 'Sequential (Hunt Group)',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::SIMULTANEOUS => 'Ring all extensions at the same time',
            self::ROUND_ROBIN => 'Distribute calls evenly across extensions',
            self::SEQUENTIAL => 'Try extensions one after another',
        };
    }
}
