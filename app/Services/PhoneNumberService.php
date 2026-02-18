<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone Number Service
 *
 * Provides robust phone number parsing, validation, and formatting using
 * Google's libphonenumber library. Handles international phone numbers,
 * country code extraction, and region identification.
 *
 * Replaces hardcoded country code mappings with industry-standard
 * phone number processing.
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
     * @param  string  $phoneNumber  Raw phone number (with or without +)
     * @return string|null The calling code (e.g., "+1", "+44") or null if unparseable
     */
    public function extractCallingCode(string $phoneNumber): ?string
    {
        try {
            // Parse the phone number
            $parsedNumber = $this->phoneUtil->parse($phoneNumber, 'US'); // Default region

            // Get the country code directly from the parsed number
            $countryCode = $parsedNumber->getCountryCode();

            return '+'.$countryCode;
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
     * Results are cached for performance.
     *
     * @return string|null The ISO country code or null if not found
     */
    public function callingCodeToCountryCode(string $callingCode): ?string
    {
        $cacheKey = "phone_country_code:{$callingCode}";

        return Cache::remember(
            $cacheKey,
            3600, // Cache for 1 hour
            function () use ($callingCode) {
                try {
                    // Use libphonenumber to get supported regions for this calling code
                    $code = (int) ltrim($callingCode, '+');
                    $regions = $this->phoneUtil->getRegionCodesForCountryCode($code);

                    if (empty($regions)) {
                        return null;
                    }

                    // Return the first region (primary region for this calling code)
                    // For shared codes (e.g., +1 for US/CA), this returns 'US' as primary
                    return $regions[0];
                } catch (\Exception $e) {
                    Log::warning('Failed to determine country code from calling code', [
                        'calling_code' => $callingCode,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            }
        );
    }

    /**
     * Validate and format a phone number.
     * Results are cached for performance.
     *
     * @param  string  $defaultRegion  Default region for parsing (e.g., 'US')
     * @return array|null Returns ['formatted' => string, 'country_code' => string, 'national_number' => string] or null if invalid
     */
    public function validateAndFormatPhoneNumber(string $phoneNumber, string $defaultRegion = 'US'): ?array
    {
        $cacheKey = "phone_validation:{$phoneNumber}:{$defaultRegion}";

        return Cache::remember(
            $cacheKey,
            1800, // Cache for 30 minutes
            function () use ($phoneNumber, $defaultRegion) {
                try {
                    $parsedNumber = $this->phoneUtil->parse($phoneNumber, $defaultRegion);

                    if (! $this->phoneUtil->isValidNumber($parsedNumber)) {
                        return null;
                    }

                    return [
                        'formatted' => $this->phoneUtil->format($parsedNumber, PhoneNumberFormat::E164),
                        'country_code' => '+'.$parsedNumber->getCountryCode(),
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
        );
    }

    /**
     * Extract phone number components.
     *
     * @return array|null Returns ['calling_code', 'national_number', 'country_code'] or null
     */
    public function extractPhoneComponents(string $phoneNumber): ?array
    {
        $validated = $this->validateAndFormatPhoneNumber($phoneNumber);

        if (! $validated) {
            return null;
        }

        return [
            'calling_code' => $validated['country_code'],
            'national_number' => $validated['national_number'],
            'country_code' => $validated['region'],
            'formatted_e164' => $validated['formatted'],
        ];
    }

    /**
     * Normalize a phone number to E.164 format.
     *
     * Simple normalization that:
     * 1. Removes all non-numeric characters except +
     * 2. Ensures the number starts with + for international format
     *
     * This is a lightweight alternative to validateAndFormatPhoneNumber()
     * when you just need consistent formatting without full validation.
     *
     * @param  string|null  $number  The raw phone number
     * @return string|null Normalized E.164 number or null if input is empty
     */
    public function normalizeToE164(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }

        // Remove all non-numeric characters except +
        $normalized = preg_replace('/[^0-9+]/', '', $number);

        // Ensure + prefix for E.164
        if (! str_starts_with($normalized, '+')) {
            $normalized = '+'.$normalized;
        }

        return $normalized;
    }

    /**
     * Strip formatting characters from phone number without adding + prefix.
     *
     * Use this when you need to compare numbers in their raw numeric form
     * without enforcing E.164 format.
     *
     * @param  string|null  $number  The raw phone number
     * @return string|null Stripped number or null if input is empty
     */
    public function stripFormatting(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }

        return preg_replace('/[^0-9+]/', '', $number);
    }
}
