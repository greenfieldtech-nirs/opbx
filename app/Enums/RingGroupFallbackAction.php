<?php

declare(strict_types=1);

namespace App\Enums;

enum RingGroupFallbackAction: string
{
    case VOICEMAIL = 'voicemail';
    case EXTENSION = 'extension';
    case HANGUP = 'hangup';
    case REPEAT = 'repeat';

    public function label(): string
    {
        return match($this) {
            self::VOICEMAIL => 'Voicemail',
            self::EXTENSION => 'Forward to Extension',
            self::HANGUP => 'Hangup',
            self::REPEAT => 'Repeat Ring Cycle',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::VOICEMAIL => 'Send caller to voicemail',
            self::EXTENSION => 'Forward call to a specific extension',
            self::HANGUP => 'End the call',
            self::REPEAT => 'Start ringing cycle again',
        };
    }
}
