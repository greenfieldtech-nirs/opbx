<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Business Hours Action Type enumeration.
 *
 * Defines the types of actions that can be taken for business hours routing.
 */
enum BusinessHoursActionType: string
{
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case CONFERENCE_ROOM = 'conference_room';
    case IVR_MENU = 'ivr_menu';
    case AI_ASSISTANT = 'ai_assistant';
    case AI_LOAD_BALANCER = 'ai_load_balancer';

    /**
     * Get human-readable label for the action type.
     */
    public function label(): string
    {
        return match ($this) {
            self::EXTENSION => 'Extension',
            self::RING_GROUP => 'Ring Group',
            self::CONFERENCE_ROOM => 'Conference Room',
            self::IVR_MENU => 'IVR Menu',
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
        };
    }

    /**
     * Get description for the action type.
     */
    public function description(): string
    {
        return match ($this) {
            self::EXTENSION => 'Route calls directly to a specific extension',
            self::RING_GROUP => 'Route calls to a ring group for simultaneous or sequential ringing',
            self::CONFERENCE_ROOM => 'Route calls to a conference room',
            self::IVR_MENU => 'Route calls to an interactive voice response menu',
            self::AI_ASSISTANT => 'Route calls to an AI-powered assistant',
            self::AI_LOAD_BALANCER => 'Route calls to an AI load balancer for distribution across multiple assistants',
        };
    }

    /**
     * Get all action types as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
