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
    case IVR_MENU = 'ivr_menu';

    /**
     * Get human-readable label for the action type.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::EXTENSION => 'Extension',
            self::RING_GROUP => 'Ring Group',
            self::IVR_MENU => 'IVR Menu',
        };
    }

    /**
     * Get description for the action type.
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::EXTENSION => 'Route calls directly to a specific extension',
            self::RING_GROUP => 'Route calls to a ring group for simultaneous or sequential ringing',
            self::IVR_MENU => 'Route calls to an interactive voice response menu',
        };
    }

    /**
     * Get all action types as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}