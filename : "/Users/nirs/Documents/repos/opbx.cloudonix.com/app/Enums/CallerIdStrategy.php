: "<?php

declare(strict_types=1);

namespace App\Enums;

enum CallerIdStrategy: string
{
    case ROUND_ROBIN = 'round_robin';
    case RANDOM = 'random';
    case LEAST_RECENTLY_USED = 'least_recently_used';

    public function label(): string
    {
        return match ($this) {
            self::ROUND_ROBIN => 'Round Robin',
            self::RANDOM => 'Random',
            self::LEAST_RECENTLY_USED => 'Least Recently Used',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ROUND_ROBIN => 'Cycle through Caller IDs sequentially',
            self::RANDOM => 'Select Caller IDs randomly',
            self::LEAST_RECENTLY_USED => 'Select the least recently used Caller ID',
        };
    }
}
