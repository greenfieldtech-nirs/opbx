<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Routing Destination Type Enum
 *
 * Represents the possible routing destinations for auto-dialer calls.
 */
enum RoutingDestinationType: string
{
    case AI_ASSISTANT = 'ai_assistant';
    case AI_LOAD_BALANCER = 'ai_load_balancer';
    case HANGUP = 'hangup';

    /**
     * Get display label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
            self::HANGUP => 'Hangup',
        };
    }

    /**
     * Check if this type requires a destination ID.
     */
    public function requiresDestinationId(): bool
    {
        return in_array($this, [self::AI_ASSISTANT, self::AI_LOAD_BALANCER], true);
    }

    /**
     * Get available routing options for select dropdown.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::AI_ASSISTANT->value => self::AI_ASSISTANT->label(),
            self::AI_LOAD_BALANCER->value => self::AI_LOAD_BALANCER->label(),
            self::HANGUP->value => self::HANGUP->label(),
        ];
    }
}
