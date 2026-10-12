<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformHealthStatus: string
{
    case OK = 'ok';
    case DEGRADED = 'degraded';
    case DOWN = 'down';
    case UNKNOWN = 'unknown';

    /** Higher is worse; unknown (optional component not running) never outranks a real failure. */
    public function severity(): int
    {
        return match ($this) {
            self::OK => 0,
            self::UNKNOWN => 1,
            self::DEGRADED => 2,
            self::DOWN => 3,
        };
    }
}
