<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Country Calling Code Service
 *
 * Provides utilities for working with international calling codes and country codes.
 */
class CountryCallingCodeService
{
    /**
     * Get the country code for a calling code.
     *
     * @param  string  $callingCode  The calling code (e.g., '+1', '+44')
     * @return string|null The ISO country code or null if not found
     */
    public function callingCodeToCountryCode(string $callingCode): ?string
    {
        $callingCodeMap = $this->getCallingCodeMap();

        return $callingCodeMap[$callingCode] ?? null;
    }

    /**
     * Get the calling codes for a country code.
     *
     * @param  string  $countryCode  The ISO country code (e.g., 'US', 'GB')
     * @return array<int, string> Array of calling codes
     */
    public function countryCodeToCallingCodes(string $countryCode): array
    {
        $callingCodeMap = $this->getCallingCodeMap();
        $countryCode = strtoupper($countryCode);

        $callingCodes = [];
        foreach ($callingCodeMap as $code => $country) {
            if ($country === $countryCode) {
                $callingCodes[] = $code;
            }
        }

        return $callingCodes;
    }

    /**
     * Check if a calling code is valid.
     *
     * @param  string  $callingCode  The calling code to validate
     * @return bool True if the calling code is valid
     */
    public function isValidCallingCode(string $callingCode): bool
    {
        // Remove + prefix if present
        $code = ltrim($callingCode, '+');

        // Check if numeric and reasonable length (1-4 digits)
        return is_numeric($code) && strlen($code) >= 1 && strlen($code) <= 4;
    }

    /**
     * Normalize a phone number to get just the calling code.
     *
     * @param  string  $phoneNumber  The phone number
     * @return string|null The normalized calling code or null
     */
    public function extractCallingCode(string $phoneNumber): ?string
    {
        // Remove common formatting characters
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $phoneNumber);

        // Check for + prefix
        if (str_starts_with($cleaned, '+')) {
            $callingCode = '+'.preg_replace('/[^0-9]/', '', $cleaned);

            // Verify it's a valid calling code format
            if ($this->isValidCallingCode($callingCode)) {
                return $callingCode;
            }
        }

        // Try to find a valid calling code in the number
        $callingCodeMap = $this->getCallingCodeMap();

        // Sort by length descending to match longer codes first
        $sortedCodes = array_keys($callingCodeMap);
        usort($sortedCodes, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sortedCodes as $code) {
            if (str_starts_with($cleaned, $code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Get the country code for a phone number.
     *
     * @param  string  $phoneNumber  The phone number
     * @return string|null The ISO country code or null
     */
    public function getCountryCodeFromPhone(string $phoneNumber): ?string
    {
        $callingCode = $this->extractCallingCode($phoneNumber);

        if ($callingCode === null) {
            return null;
        }

        return $this->callingCodeToCountryCode($callingCode);
    }

    /**
     * Get all available calling codes.
     *
     * @return array<string, string> Calling code => Country code map
     */
    public function getAllCallingCodes(): array
    {
        return $this->getCallingCodeMap();
    }

    /**
     * Get all country codes with their calling codes.
     *
     * @return array<string, array> Country code => [calling codes]
     */
    public function getAllCountries(): array
    {
        $callingCodeMap = $this->getCallingCodeMap();
        $countries = [];

        foreach ($callingCodeMap as $code => $country) {
            if (! isset($countries[$country])) {
                $countries[$country] = [];
            }
            $countries[$country][] = $code;
        }

        // Sort country codes
        ksort($countries);

        return $countries;
    }

    /**
     * Check if a phone number is from a specific country.
     *
     * @param  string  $phoneNumber  The phone number
     * @param  string  $countryCode  The ISO country code
     * @return bool True if the phone number is from the country
     */
    public function isPhoneFromCountry(string $phoneNumber, string $countryCode): bool
    {
        $phoneCountry = $this->getCountryCodeFromPhone($phoneNumber);

        return $phoneCountry !== null && strtoupper($phoneCountry) === strtoupper($countryCode);
    }

    /**
     * Get the calling code map from config.
     *
     * @return array<string, string>
     */
    protected function getCallingCodeMap(): array
    {
        return Config::get('country_calling_codes', []);
    }
}
