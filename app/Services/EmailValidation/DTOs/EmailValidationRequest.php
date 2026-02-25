<?php

declare(strict_types=1);

namespace App\Services\EmailValidation\DTOs;

/**
 * Email Validation Request DTO
 *
 * Encapsulates an email address for validation.
 */
readonly class EmailValidationRequest
{
    /**
     * Create a new email validation request.
     *
     * @param  string  $email  The email address to validate
     */
    public function __construct(
        public string $email
    ) {}

    /**
     * Create a request instance from a raw email string.
     */
    public static function fromString(string $email): self
    {
        return new self($email);
    }
}
