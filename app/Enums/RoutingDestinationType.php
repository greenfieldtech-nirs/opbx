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
    case RING_GROUP = 'ring_group';
    case CALL_QUEUE = 'call_queue';
    case HANGUP = 'hangup';

    /**
     * Get display label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
            self::RING_GROUP => 'Ring Group',
            self::CALL_QUEUE => 'Call Queue',
            self::HANGUP => 'Hang Up',
        };
    }

    /**
     * Check if this type requires a destination ID.
     */
    public function requiresDestinationId(): bool
    {
        return $this !== self::HANGUP;
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
            self::RING_GROUP->value => self::RING_GROUP->label(),
            self::CALL_QUEUE->value => self::CALL_QUEUE->label(),
            self::HANGUP->value => self::HANGUP->label(),
        ];
    }
}
