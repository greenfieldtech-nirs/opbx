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

/**
 * CSV Validation Result
 */
readonly class CsvValidationResult
{
    /**
     * @param  array<int, array{phone_number: string, description: string}>  $validRows
     * @param  array<int, array{row: int, phone_number: string, error: string}>  $invalidRows
     * @param  array<int, array{phone_number: string, row: int, kept_row: int}>  $duplicates
     */
    public function __construct(
        public int $totalRows,
        public array $validRows,
        public array $invalidRows,
        public array $duplicates,
        public bool $success,
        public ?string $error = null,
    ) {}

    public function validCount(): int
    {
        return count($this->validRows);
    }

    public function invalidCount(): int
    {
        return count($this->invalidRows);
    }

    public function duplicateCount(): int
    {
        return count($this->duplicates);
    }
}

/**
 * Batch Validation Result
 */
readonly class BatchValidationResult
{
    /**
     * @param  array<int, ValidationResult>  $results
     */
    public function __construct(
        public array $results,
        public int $validCount,
        public int $invalidCount,
    ) {}
}
