<?php

declare(strict_types=1);

namespace App\Enums;

enum AlbsStrategy: string
{
    case ROUND_ROBIN = 'round_robin';
    case PRIORITY = 'priority';
    case PERCENTAGE = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::ROUND_ROBIN => 'Round Robin',
            self::PRIORITY => 'Priority Based',
            self::PERCENTAGE => 'Percentage Based',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ROUND_ROBIN => 'Distribute calls evenly across all AI assistants in sequence',
            self::PRIORITY => 'Always route to highest priority (lowest number) AI assistant',
            self::PERCENTAGE => 'Route based on configured weight percentages',
        };
    }
}
