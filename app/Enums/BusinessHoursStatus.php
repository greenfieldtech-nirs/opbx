<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Business Hours Schedule Status enumeration.
 *
 * Defines the possible statuses for a business hours schedule.
 */
enum BusinessHoursStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * Get all enum values as an array.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn(self $status) => $status->value, self::cases());
    }

    /**
     * Get the display label for this status.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
        };
    }
}
