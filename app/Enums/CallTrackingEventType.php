<?php

declare(strict_types=1);

namespace App\Enums;

enum CallTrackingEventType: string
{
    case CALL_RECEIVED = 'call.received';
    case CALL_ANSWERED = 'call.answered';
    case CALL_MISSED = 'call.missed';
    case CALL_CONVERTED = 'call.converted';
    case CALL_FAILED = 'call.failed';

    public function label(): string
    {
        return match ($this) {
            self::CALL_RECEIVED => 'Call Received',
            self::CALL_ANSWERED => 'Call Answered',
            self::CALL_MISSED => 'Call Missed',
            self::CALL_CONVERTED => 'Call Converted',
            self::CALL_FAILED => 'Call Failed',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
