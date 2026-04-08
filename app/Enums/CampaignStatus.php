<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Campaign Status Enum
 *
 * Represents the possible states of an auto-dialer campaign.
 */
enum CampaignStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';

    /**
     * Get display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::COMPLETED => 'Completed',
            self::ARCHIVED => 'Archived',
        };
    }

    /**
     * Get color for the status badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::ACTIVE => 'green',
            self::PAUSED => 'yellow',
            self::COMPLETED => 'blue',
            self::ARCHIVED => 'red',
        };
    }

    /**
     * Check if the campaign can be started.
     */
    public function canStart(): bool
    {
        return in_array($this, [self::DRAFT, self::PAUSED], true);
    }

    /**
     * Check if the campaign can be paused.
     */
    public function canPause(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if the campaign is runnable (can process calls).
     */
    public function isRunnable(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if the campaign can accept a list assignment.
     */
    public function canAcceptList(): bool
    {
        return in_array($this, [self::DRAFT, self::ACTIVE], true);
    }
}
