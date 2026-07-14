<?php

declare(strict_types=1);

namespace App\Enums;

enum EmbedIconPosition: string
{
    case BOTTOM_RIGHT = 'bottom-right';
    case BOTTOM_LEFT = 'bottom-left';
    case TOP_RIGHT = 'top-right';
    case TOP_LEFT = 'top-left';

    public static function default(): self
    {
        return self::BOTTOM_RIGHT;
    }
}
