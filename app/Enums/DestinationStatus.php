<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Destination Status Enum
 *
 * Represents the possible states of a destination in an auto-dialer campaign.
 */
enum DestinationStatus: string
{
    case PENDING = 'pending';
    case DIALING = 'dialing';
    case CONNECTED = 'connected';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case INVALID = 'invalid';

    /**
     * Get display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::DIALING => 'Dialing',
            self::CONNECTED => 'Connected',
            self::FAILED => 'Failed',
            self::COMPLETED => 'Completed',
            self::INVALID => 'Invalid',
        };
    }

    /**
     * Get color for the status badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::DIALING => 'yellow',
            self::CONNECTED => 'green',
            self::FAILED => 'red',
            self::COMPLETED => 'blue',
            self::INVALID => 'purple',
        };
    }

    /**
     * Check if the destination is final (no more attempts).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::INVALID], true);
    }

    /**
     * Check if the destination can be dialed.
     */
    public function canDial(): bool
    {
        return in_array($this, [self::PENDING, self::FAILED], true);
    }
}
