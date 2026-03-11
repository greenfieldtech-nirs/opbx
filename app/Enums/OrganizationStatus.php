<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Organization Status Enum
 *
 * Defines the possible statuses for an organization in the system.
 * Used for platform-level organization management.
 */
enum OrganizationStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case DELETED = 'deleted';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::DELETED => 'Deleted',
        };
    }

    /**
     * Get color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'green',
            self::SUSPENDED => 'yellow',
            self::DELETED => 'red',
        };
    }

    /**
     * Check if the status allows user authentication.
     */
    public function allowsAuthentication(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Get all valid status values for validation.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
