<?php

declare(strict_types=1);

namespace App\Enums;

enum QueueCallDisposition: string
{
    case ANSWERED = 'answered';
    case ABANDONED = 'abandoned';
    case OVERFLOW = 'overflow';

    public function label(): string
    {
        return match ($this) {
            self::ANSWERED => 'Answered',
            self::ABANDONED => 'Abandoned',
            self::OVERFLOW => 'Overflow',
        };
    }
}
