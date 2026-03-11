<?php

declare(strict_types=1);

namespace App\Enums;

enum IvrDestinationType: string
{
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case CONFERENCE_ROOM = 'conference_room';
    case IVR_MENU = 'ivr_menu';
    case BUSINESS_HOURS = 'business_hours';
    case AI_ASSISTANT = 'ai_assistant';
    case AI_LOAD_BALANCER = 'ai_load_balancer';
    case HANGUP = 'hangup';

    public function label(): string
    {
        return match ($this) {
            self::EXTENSION => 'Extension',
            self::RING_GROUP => 'Ring Group',
            self::CONFERENCE_ROOM => 'Conference Room',
            self::IVR_MENU => 'IVR Menu',
            self::BUSINESS_HOURS => 'Business Hours',
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
            self::HANGUP => 'Hang Up',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EXTENSION => 'Route to a specific extension',
            self::RING_GROUP => 'Route to a ring group for multiple extensions',
            self::CONFERENCE_ROOM => 'Route to a conference room',
            self::IVR_MENU => 'Route to another IVR menu',
            self::BUSINESS_HOURS => 'Route based on business hours schedule',
            self::AI_ASSISTANT => 'Route to an AI assistant',
            self::AI_LOAD_BALANCER => 'Route to an AI load balancer',
            self::HANGUP => 'End the call',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EXTENSION => 'user',
            self::RING_GROUP => 'users',
            self::CONFERENCE_ROOM => 'message-circle',
            self::IVR_MENU => 'menu',
            self::BUSINESS_HOURS => 'clock',
            self::AI_ASSISTANT => 'bot',
            self::AI_LOAD_BALANCER => 'scale',
            self::HANGUP => 'phone-off',
        };
    }
}
