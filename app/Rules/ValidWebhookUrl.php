<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Security\SsrfUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule for webhook URLs that prevents SSRF attacks.
 *
 * Ensures webhook URLs cannot point to internal network resources
 * such as private IP ranges, localhost, or internal service hostnames.
 */
class ValidWebhookUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The attribute being validated
     * @param  mixed  $value  The value being validated
     * @param  Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail  The callback to call on failure
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $validator = new SsrfUrlValidator;

        if (! $validator->isValid($value)) {
            $fail('The :attribute must be a valid, publicly accessible URL. Internal network addresses are not allowed.');
        }
    }
}
