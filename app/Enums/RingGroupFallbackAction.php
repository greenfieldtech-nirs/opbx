<?php

declare(strict_types=1);

namespace App\Enums;

enum RingGroupFallbackAction: string
{
    case EXTENSION = 'extension';
    case HANGUP = 'hangup';

    public function label(): string
    {
        return match($this) {
            self::EXTENSION => 'Forward to Extension',
            self::HANGUP => 'Hangup',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::EXTENSION => 'Forward call to a specific extension',
            self::HANGUP => 'End the call',
        };
    }
}
