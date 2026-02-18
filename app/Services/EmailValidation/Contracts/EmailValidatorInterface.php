<?php

declare(strict_types=1);

namespace App\Services\EmailValidation\Contracts;

use App\Services\EmailValidation\DTOs\EmailValidationResult;

/**
 * Email Validator Interface
 *
 * Defines the contract for email validation services.
 * Implementations must validate email addresses against external services
 * and return a structured result.
 *
 * IMPORTANT: This is a BLOCKING validation. If the validation service
 * is unavailable, the validation MUST fail (return isValid = false).
 */
interface EmailValidatorInterface
{
    /**
     * Validate an email address.
     *
     * This method performs a real-time validation check against an external
     * email validation service. The check is blocking - if the service is
     * unavailable or returns an error, the validation fails.
     *
     * @param  string  $email  The email address to validate
     * @return EmailValidationResult The validation result with detailed information
     */
    public function validate(string $email): EmailValidationResult;
}
