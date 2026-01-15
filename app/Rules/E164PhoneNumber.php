<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a string is a valid E.164 phone number.
 * Format: +[1-9] followed by 1 to 14 digits.
 */
class E164PhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute must be a string.');
            return;
        }

        // E.164 Regex: Starts with +, followed by 1-9 (country code), then 1-14 digits
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $value)) {
            $fail('The :attribute must be in valid E.164 format (e.g., +12125551234).');
        }
    }
}
