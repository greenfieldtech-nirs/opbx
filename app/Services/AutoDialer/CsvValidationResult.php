<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

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
