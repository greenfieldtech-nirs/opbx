<?php

declare(strict_types=1);

namespace App\Enums;

enum InboundBlacklistRejectionStrategy: string
{
    case DROP = 'drop';
    case REJECT = 'reject';
    case TORMENT = 'torment';

    public function label(): string
    {
        return match ($this) {
            self::DROP => 'Drop (Silent)',
            self::REJECT => 'Reject (With Message)',
            self::TORMENT => 'Torment (Music Loop)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DROP => 'Immediately hang up without any message',
            self::REJECT => 'Play "Your call has been rejected" then hang up',
            self::TORMENT => 'Put caller in a random conference room with hold music',
        };
    }
}
