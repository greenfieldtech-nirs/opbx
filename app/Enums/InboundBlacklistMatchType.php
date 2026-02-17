<?php

declare(strict_types=1);

namespace App\Enums;

enum InboundBlacklistMatchType: string
{
    case EXACT = 'exact';
    case PREFIX = 'prefix';
    case WILDCARD = 'wildcard';

    public function label(): string
    {
        return match ($this) {
            self::EXACT => 'Exact Match',
            self::PREFIX => 'Prefix Match',
            self::WILDCARD => 'Wildcard Pattern',
        };
    }
}
