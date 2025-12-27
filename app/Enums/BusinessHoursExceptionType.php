<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Business Hours Exception Type enumeration.
 *
 * Defines the types of exceptions in a business hours schedule.
 */
enum BusinessHoursExceptionType: string
{
    case CLOSED = 'closed';
    case SPECIAL_HOURS = 'special_hours';

    /**
     * Get all enum values as an array.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn(self $type) => $type->value, self::cases());
    }

    /**
     * Get the display label for this exception type.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::CLOSED => 'Closed',
            self::SPECIAL_HOURS => 'Special Hours',
        };
    }
}
