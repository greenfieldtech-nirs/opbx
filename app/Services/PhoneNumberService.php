<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;

/**
 * Phone Number Service
 *
 * Provides robust phone number parsing, validation, and formatting using
 * Google's libphonenumber library. Handles international phone numbers,
 * country code extraction, and region identification.
 *
 * Replaces hardcoded country code mappings with industry-standard
 * phone number processing.
 *
 * @package App\Services
 */
class PhoneNumberService
{
    private PhoneNumberUtil $phoneUtil;

    /**
     * Initialize the phone number service with libphonenumber instance.
     */
    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    /**
     * Extract calling code from a phone number.
     *
     * Uses libphonenumber for robust parsing and validation to determine
     * the country calling code (e.g., +1 for US/Canada, +44 for UK).
     *
     * @param string $phoneNumber Raw phone number (with or without +)
     * @return string|null The calling code (e.g., "+1", "+44") or null if unparseable
     */
    public function extractCallingCode(string $phoneNumber): ?string
    {
        try {
            // Parse the phone number
            $parsedNumber = $this->phoneUtil->parse($phoneNumber, 'US'); // Default region

            // Get the country code
            $countryCode = $this->phoneUtil->getCountryCodeForRegion(
                $this->phoneUtil->getRegionCodeForNumber($parsedNumber)
            );

            return '+' . $countryCode;
        } catch (NumberParseException $e) {
            Log::warning('Failed to parse phone number for calling code extraction', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert calling code to country code.
     * Uses libphonenumber for accurate country code mapping.
     *
     * @param string $callingCode
     * @return string|null The ISO country code or null if not found
     */
    public function callingCodeToCountryCode(string $callingCode): ?string
    {
        try {
            // Parse a dummy number with this calling code to get the region
            $dummyNumber = $callingCode . '1234567890';
            $parsedNumber = $this->phoneUtil->parse($dummyNumber, 'US');

            $region = $this->phoneUtil->getRegionCodeForNumber($parsedNumber);

            // If region is ZZ (unknown), return null
            return $region === 'ZZ' ? null : $region;
        } catch (NumberParseException $e) {
            Log::warning('Failed to determine country code from calling code', [
                'calling_code' => $callingCode,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Validate and format a phone number.
     *
     * @param string $phoneNumber
     * @param string $defaultRegion Default region for parsing (e.g., 'US')
     * @return array|null Returns ['formatted' => string, 'country_code' => string, 'national_number' => string] or null if invalid
     */
    public function validateAndFormatPhoneNumber(string $phoneNumber, string $defaultRegion = 'US'): ?array
    {
        try {
            $parsedNumber = $this->phoneUtil->parse($phoneNumber, $defaultRegion);

            if (!$this->phoneUtil->isValidNumber($parsedNumber)) {
                return null;
            }

            return [
                'formatted' => $this->phoneUtil->format($parsedNumber, PhoneNumberFormat::E164),
                'country_code' => '+' . $parsedNumber->getCountryCode(),
                'national_number' => $parsedNumber->getNationalNumber(),
                'region' => $this->phoneUtil->getRegionCodeForNumber($parsedNumber),
                'is_valid' => true,
            ];
        } catch (NumberParseException $e) {
            Log::warning('Failed to validate phone number', [
                'phone_number' => $phoneNumber,
                'default_region' => $defaultRegion,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract phone number components.
     *
     * @param string $phoneNumber
     * @return array|null Returns ['calling_code', 'national_number', 'country_code'] or null
     */
    public function extractPhoneComponents(string $phoneNumber): ?array
    {
        $validated = $this->validateAndFormatPhoneNumber($phoneNumber);

        if (!$validated) {
            return null;
        }

        return [
            'calling_code' => $validated['country_code'],
            'national_number' => $validated['national_number'],
            'country_code' => $validated['region'],
            'formatted_e164' => $validated['formatted'],
        ];
    }
}