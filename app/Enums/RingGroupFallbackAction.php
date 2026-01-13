<?php

declare(strict_types=1);

namespace App\Enums;

enum RingGroupFallbackAction: string
{
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case IVR_MENU = 'ivr_menu';
    case AI_ASSISTANT = 'ai_assistant';
    case HANGUP = 'hangup';

    public function label(): string
    {
        return match($this) {
            self::EXTENSION => 'Forward to Extension',
            self::RING_GROUP => 'Forward to Ring Group',
            self::IVR_MENU => 'Forward to IVR Menu',
            self::AI_ASSISTANT => 'Forward to AI Assistant',
            self::HANGUP => 'Hangup',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::EXTENSION => 'Forward call to a specific extension',
            self::RING_GROUP => 'Forward call to another ring group',
            self::IVR_MENU => 'Forward call to an IVR menu',
            self::AI_ASSISTANT => 'Forward call to an AI assistant',
            self::HANGUP => 'End the call',
        };
    }
}
