<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use League\Csv\Reader;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Service for validating distribution list phone numbers
 */
class ListValidationService
{
    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    /**
     * Validate a single phone number.
     *
     * @param  string  $phoneNumber  The phone number to validate
     * @param  string|null  $defaultRegion  Default region code (e.g., 'US', 'GB')
     */
    public function validatePhoneNumber(
        string $phoneNumber,
        ?string $defaultRegion = 'US',
    ): ValidationResult {
        // Clean the input
        $phoneNumber = $this->cleanPhoneNumber($phoneNumber);

        if (empty($phoneNumber)) {
            return ValidationResult::failure('Phone number is empty');
        }

        try {
            $phoneNumberProto = $this->phoneUtil->parse($phoneNumber, $defaultRegion);

            // Check if valid
            if (! $this->phoneUtil->isValidNumber($phoneNumberProto)) {
                return ValidationResult::failure('Invalid phone number format');
            }

            // Check number type
            $numberType = $this->phoneUtil->getNumberType($phoneNumberProto);
            $typeString = $this->getNumberTypeString($numberType);

            // Get region
            $region = $this->phoneUtil->getRegionCodeForNumber($phoneNumberProto);

            // Format to E.164
            $normalizedNumber = $this->phoneUtil->format($phoneNumberProto, PhoneNumberFormat::E164);

            return ValidationResult::success(
                normalizedNumber: $normalizedNumber,
                numberType: $typeString,
                region: $region,
            );
        } catch (NumberParseException $e) {
            return ValidationResult::failure($this->getParseErrorMessage($e));
        }
    }

    /**
     * Validate multiple phone numbers in batch.
     *
     * @param  array<int, array{phone_number: string, description: string}>  $entries
     */
    public function batchValidate(
        array $entries,
        ?string $defaultRegion = 'US',
    ): BatchValidationResult {
        $results = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($entries as $index => $entry) {
            $result = $this->validatePhoneNumber($entry['phone_number'], $defaultRegion);
            $results[$index] = $result;

            if ($result->valid) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        return new BatchValidationResult($results, $validCount, $invalidCount);
    }

    /**
     * Validate a CSV file.
     *
     * @param  string  $filePath  Path to the CSV file
     * @param  int  $organizationId  Organization ID for context
     * @param  string|null  $defaultRegion  Default region for validation
     */
    public function validateCsvFile(
        string $filePath,
        int $organizationId,
        ?string $defaultRegion = 'US',
    ): CsvValidationResult {
        try {
            $reader = Reader::createFromPath($filePath, 'r');
            $reader->setHeaderOffset(0);

            $headers = $reader->getHeader();

            // Validate required columns
            if (! in_array('phone_number', $headers, true)) {
                return new CsvValidationResult(
                    totalRows: 0,
                    validRows: [],
                    invalidRows: [],
                    duplicates: [],
                    success: false,
                    error: 'CSV must contain a "phone_number" column',
                );
            }

            $records = $reader->getRecords();
            $validRows = [];
            $invalidRows = [];
            $seenNumbers = []; // Track duplicates
            $duplicates = [];
            $rowNumber = 0;

            foreach ($records as $record) {
                $rowNumber++;

                // Skip empty rows
                if (empty($record['phone_number'])) {
                    continue;
                }

                $phoneNumber = trim($record['phone_number']);
                $description = isset($record['description']) ? trim($record['description']) : null;

                // Check for duplicates within the file
                $normalizedForDedup = $this->normalizeForDedup($phoneNumber);

                if (isset($seenNumbers[$normalizedForDedup])) {
                    $duplicates[] = [
                        'phone_number' => $phoneNumber,
                        'row' => $rowNumber,
                        'kept_row' => $seenNumbers[$normalizedForDedup],
                    ];

                    continue; // Skip duplicate
                }

                // Validate phone number
                $validationResult = $this->validatePhoneNumber($phoneNumber, $defaultRegion);

                if ($validationResult->valid) {
                    $validRows[] = [
                        'phone_number' => $validationResult->normalizedNumber,
                        'description' => $description,
                    ];
                    $seenNumbers[$normalizedForDedup] = $rowNumber;
                } else {
                    $invalidRows[] = [
                        'row' => $rowNumber,
                        'phone_number' => $phoneNumber,
                        'error' => $validationResult->error,
                    ];
                }
            }

            return new CsvValidationResult(
                totalRows: $rowNumber,
                validRows: $validRows,
                invalidRows: $invalidRows,
                duplicates: $duplicates,
                success: true,
            );
        } catch (\Exception $e) {
            return new CsvValidationResult(
                totalRows: 0,
                validRows: [],
                invalidRows: [],
                duplicates: [],
                success: false,
                error: 'Failed to parse CSV: '.$e->getMessage(),
            );
        }
    }

    /**
     * Find duplicates within a list of phone numbers.
     *
     * @param  array<int, string>  $phoneNumbers
     * @return array<int, array{phone_number: string, indices: array<int, int>}>
     */
    public function findDuplicates(array $phoneNumbers): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($phoneNumbers as $index => $phoneNumber) {
            $normalized = $this->normalizeForDedup($phoneNumber);

            if (isset($seen[$normalized])) {
                if (! isset($duplicates[$normalized])) {
                    $duplicates[$normalized] = [
                        'phone_number' => $phoneNumber,
                        'indices' => [$seen[$normalized]],
                    ];
                }
                $duplicates[$normalized]['indices'][] = $index;
            } else {
                $seen[$normalized] = $index;
            }
        }

        return array_values($duplicates);
    }

    /**
     * Clean phone number input.
     */
    private function cleanPhoneNumber(string $phoneNumber): string
    {
        // Remove common formatting characters
        $cleaned = preg_replace('/[\s\-\.\(\)\[\]]/', '', $phoneNumber);

        return trim($cleaned);
    }

    /**
     * Normalize phone number for deduplication.
     */
    private function normalizeForDedup(string $phoneNumber): string
    {
        $cleaned = $this->cleanPhoneNumber($phoneNumber);

        // Remove leading + for comparison
        return ltrim($cleaned, '+');
    }

    /**
     * Get human-readable number type.
     */
    private function getNumberTypeString(int $numberType): string
    {
        return match ($numberType) {
            PhoneNumberType::MOBILE => 'MOBILE',
            PhoneNumberType::FIXED_LINE => 'FIXED_LINE',
            PhoneNumberType::FIXED_LINE_OR_MOBILE => 'FIXED_LINE_OR_MOBILE',
            PhoneNumberType::TOLL_FREE => 'TOLL_FREE',
            PhoneNumberType::PREMIUM_RATE => 'PREMIUM_RATE',
            PhoneNumberType::SHARED_COST => 'SHARED_COST',
            PhoneNumberType::VOIP => 'VOIP',
            PhoneNumberType::PERSONAL_NUMBER => 'PERSONAL_NUMBER',
            PhoneNumberType::PAGER => 'PAGER',
            PhoneNumberType::UAN => 'UAN',
            PhoneNumberType::UNKNOWN => 'UNKNOWN',
            default => 'OTHER',
        };
    }

    /**
     * Get error message from parse exception.
     */
    private function getParseErrorMessage(NumberParseException $e): string
    {
        return match ($e->getErrorType()) {
            NumberParseException::INVALID_COUNTRY_CODE => 'Invalid country code',
            NumberParseException::NOT_A_NUMBER => 'Not a valid phone number',
            NumberParseException::TOO_SHORT_NSN => 'Phone number is too short',
            NumberParseException::TOO_SHORT_AFTER_IDD => 'Phone number is too short after IDD',
            NumberParseException::TOO_LONG => 'Phone number is too long',
            default => 'Invalid phone number format',
        };
    }
}
