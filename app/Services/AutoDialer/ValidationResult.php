<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

/**
 * Phone Number Validation Result Value Object
 */
readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $normalizedNumber = null,
        public ?string $error = null,
        public ?string $carrier = null,
        public ?string $numberType = null,
        public ?string $region = null,
    ) {}

    /**
     * Create a successful validation result.
     */
    public static function success(
        string $normalizedNumber,
        ?string $carrier = null,
        ?string $numberType = null,
        ?string $region = null,
    ): self {
        return new self(
            valid: true,
            normalizedNumber: $normalizedNumber,
            carrier: $carrier,
            numberType: $numberType,
            region: $region,
        );
    }

    /**
     * Create a failed validation result.
     */
    public static function failure(string $error): self
    {
        return new self(
            valid: false,
            error: $error,
        );
    }
}
