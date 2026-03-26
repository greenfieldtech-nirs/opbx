<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI Assistant Status enumeration.
 *
 * Defines the operational status of an AI assistant.
 */
enum AiAssistantStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }

    /**
     * Get description for the status.
     */
    public function description(): string
    {
        return match ($this) {
            self::ACTIVE => 'AI assistant is active and can handle calls',
            self::INACTIVE => 'AI assistant is temporarily disabled',
        };
    }

    /**
     * Check if the status is active.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if the status is inactive.
     */
    public function isInactive(): bool
    {
        return $this === self::INACTIVE;
    }
}
