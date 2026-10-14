<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * The end of a business-hours time range: HH:mm, or 24:00 for end of day (so a whole day is 00:00-24:00),
 * and later than the sibling start_time. Ranges are half-open [start, end), compared as strings by routing.
 */
final class TimeRangeEnd implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/', $value) !== 1) {
            $fail('End time must be in HH:mm format (24:00 for end of day).');

            return;
        }

        $start = Arr::get($this->data, preg_replace('/end_time$/', 'start_time', $attribute));

        // Zero-padded HH:mm strings order like times; a malformed start is reported on its own field.
        if (is_string($start) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1 && $value <= $start) {
            $fail('End time must be after start time.');
        }
    }
}
