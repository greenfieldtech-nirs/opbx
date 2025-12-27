<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Day of Week enumeration.
 *
 * Defines the days of the week for business hours schedules.
 */
enum DayOfWeek: string
{
    case MONDAY = 'monday';
    case TUESDAY = 'tuesday';
    case WEDNESDAY = 'wednesday';
    case THURSDAY = 'thursday';
    case FRIDAY = 'friday';
    case SATURDAY = 'saturday';
    case SUNDAY = 'sunday';

    /**
     * Get all enum values as an array.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn(self $day) => $day->value, self::cases());
    }

    /**
     * Get the display label for this day.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::MONDAY => 'Monday',
            self::TUESDAY => 'Tuesday',
            self::WEDNESDAY => 'Wednesday',
            self::THURSDAY => 'Thursday',
            self::FRIDAY => 'Friday',
            self::SATURDAY => 'Saturday',
            self::SUNDAY => 'Sunday',
        };
    }

    /**
     * Get abbreviated day name.
     *
     * @return string
     */
    public function abbreviation(): string
    {
        return match($this) {
            self::MONDAY => 'Mon',
            self::TUESDAY => 'Tue',
            self::WEDNESDAY => 'Wed',
            self::THURSDAY => 'Thu',
            self::FRIDAY => 'Fri',
            self::SATURDAY => 'Sat',
            self::SUNDAY => 'Sun',
        };
    }

    /**
     * Get from Carbon day of week number (0 = Sunday, 6 = Saturday).
     *
     * @param int $dayNumber
     * @return self|null
     */
    public static function fromCarbonDayOfWeek(int $dayNumber): ?self
    {
        return match($dayNumber) {
            0 => self::SUNDAY,
            1 => self::MONDAY,
            2 => self::TUESDAY,
            3 => self::WEDNESDAY,
            4 => self::THURSDAY,
            5 => self::FRIDAY,
            6 => self::SATURDAY,
            default => null,
        };
    }
}
