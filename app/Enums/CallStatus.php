<?php

declare(strict_types=1);

namespace App\Enums;

enum CallStatus: string
{
    case INITIATED = 'initiated';
    case RINGING = 'ringing';
    case ANSWERED = 'answered';
    case COMPLETED = 'completed';
    case BUSY = 'busy';
    case NO_ANSWER = 'no_answer';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::BUSY,
            self::NO_ANSWER,
            self::FAILED
        ], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::INITIATED,
            self::RINGING,
            self::ANSWERED
        ], true);
    }
}
