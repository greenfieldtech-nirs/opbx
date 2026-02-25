<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\EmailValidation\Contracts\EmailValidatorInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * Valid Email Domain Validation Rule
 *
 * Validates email addresses using the UserCheck.com API to prevent
 * disposable emails, spam domains, and other low-quality addresses
 * from being used during registration.
 *
 * This is a BLOCKING validation - if the API is unavailable, validation fails.
 */
class ValidEmailDomain implements ValidationRule
{
    /**
     * The email validator instance.
     */
    private EmailValidatorInterface $validator;

    /**
     * Create a new rule instance.
     */
    public function __construct()
    {
        $this->validator = app(EmailValidatorInterface::class);
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the attribute being validated
     * @param  mixed  $value  The value of the attribute
     * @param  Closure  $fail  The callback to call if validation fails
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Skip validation if value is not a string
        if (! is_string($value)) {
            $fail('validation.email')->translate();

            return;
        }

        $result = $this->validator->validate($value);

        if (! $result->isValid) {
            $message = $this->getErrorMessage($result);
            $fail($message);

            // Log the validation failure for monitoring
            Log::info('Email domain validation failed', [
                'reason' => $result->failedReason ?? 'unknown',
                'domain_hash' => $this->hashDomain($value),
            ]);
        }
    }

    /**
     * Get the error message based on the validation result.
     *
     * @param  \App\Services\EmailValidation\DTOs\EmailValidationResult  $result
     */
    private function getErrorMessage($result): string
    {
        // Return specific message based on which check failed
        if ($result->isDisposable) {
            return 'Disposable email addresses are not allowed.';
        }

        if ($result->isBlocklisted) {
            return 'This email domain is not allowed.';
        }

        if ($result->isSpam) {
            return 'This email address cannot be used.';
        }

        if ($result->isRoleAccount) {
            return 'Role-based email addresses (e.g., admin@, info@) are not allowed.';
        }

        if ($result->isRelayDomain) {
            return 'Email forwarding addresses are not allowed.';
        }

        if ($result->isPublicDomain) {
            return 'Public email providers are not allowed for this registration.';
        }

        // If there's a typo suggestion, include it
        if ($result->suggestedEmail) {
            return "Did you mean {$result->suggestedEmail}?";
        }

        // Use the error message from the result if available
        if ($result->errorMessage) {
            return $result->errorMessage;
        }

        return 'Email validation failed. Please try again.';
    }

    /**
     * Hash the domain for logging (privacy).
     */
    private function hashDomain(string $email): string
    {
        $parts = explode('@', $email);
        $domain = $parts[1] ?? $email;

        return hash('sha256', strtolower($domain));
    }
}
