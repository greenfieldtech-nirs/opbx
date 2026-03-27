<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

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
