<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Distribution List Status Enum
 *
 * Represents the lifecycle states of an auto-dialer distribution list.
 */
enum ListStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
    case IN_USE = 'in_use';
    case USED = 'used';
    case ARCHIVED = 'archived';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::READY => 'Ready',
            self::FAILED => 'Failed',
            self::IN_USE => 'In Use',
            self::USED => 'Used',
            self::ARCHIVED => 'Archived',
        };
    }

    /**
     * Get color for UI badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'yellow',
            self::PROCESSING => 'blue',
            self::READY => 'green',
            self::FAILED => 'red',
            self::IN_USE => 'purple',
            self::USED => 'orange',
            self::ARCHIVED => 'gray',
        };
    }

    /**
     * Check if list can be archived in this status.
     */
    public function canArchive(): bool
    {
        return in_array($this, [self::READY, self::FAILED, self::USED], true);
    }

    /**
     * Check if list can be assigned to a campaign.
     */
    public function canAssign(): bool
    {
        return $this === self::READY;
    }

    /**
     * Check if destinations can be uploaded in this status.
     */
    public function canUpload(): bool
    {
        return in_array($this, [self::DRAFT, self::PENDING, self::FAILED], true);
    }

    /**
     * Check if list is in a final state (no further transitions possible).
     */
    public function isFinal(): bool
    {
        return $this === self::ARCHIVED;
    }

    /**
     * Check if list is currently locked (in use or used).
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::IN_USE, self::USED], true);
    }

    /**
     * Check if list can be copied.
     */
    public function canCopy(): bool
    {
        return in_array($this, [self::READY, self::IN_USE, self::USED, self::ARCHIVED], true);
    }

    /**
     * Get all statuses that allow version creation.
     */
    public function canCreateVersion(): bool
    {
        return in_array($this, [self::READY, self::USED], true);
    }

    /**
     * Get all values as array.
     *
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $status) => $carry + [$status->value => $status->label()],
            []
        );
    }
}
