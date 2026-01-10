/**
 * Country utilities using libphonenumber-js and i18n-iso-countries
 */

import * as countries from 'i18n-iso-countries';
import { getCountryCallingCode } from 'libphonenumber-js';

export interface CountryOption {
  code: string; // ISO 3166-1 alpha-2 (e.g., 'US')
  name: string; // Country name (e.g., 'United States')
  callingCode: string; // Calling code (e.g., '1')
  flag: string; // Flag emoji
}

/**
 * Legacy interface for backward compatibility
 */
export interface Country {
  name: string;
  code: string; // ISO 3166-1 alpha-2 code
}

/**
 * Get all countries with their calling codes and names
 */
export function getCountryOptions(): CountryOption[] {
  const countryCodes = countries.getAlpha2Codes();
    const regionNames = new Intl.DisplayNames(
        ['en'], {type: 'region'}
    );

  return Object.keys(countryCodes)
    .map(code => {
      try {
        const name = regionNames.of(countries.getName(code, 'en') || code);
        const callingCode = getCountryCallingCode(code as any);

        // Get flag emoji using regional indicator symbols
        const flag = code
          .toUpperCase()
          .split('')
          .map(char => String.fromCodePoint(0x1F1E6 + char.charCodeAt(0) - 65))
          .join('');

        return {
          code,
          name,
          callingCode,
          flag,
        };
      } catch (error) {
        // Skip countries that don't have calling codes
        return null;
      }
    })
    .filter(Boolean)
    .sort((a, b) => a!.name.localeCompare(b!.name)) as CountryOption[];
}

/**
 * Get country option by code
 */
export function getCountryByCode(code: string): CountryOption | undefined {
  const countries = getCountryOptions();
  return countries.find(country => country.code === code.toUpperCase());
}

/**
 * Get country option by calling code
 */
export function getCountryByCallingCode(callingCode: string): CountryOption | undefined {
  const countries = getCountryOptions();
  return countries.find(country => country.callingCode === callingCode);
}

// ============================================================================
// Legacy functions for backward compatibility
// ============================================================================

/**
 * Comprehensive list of 249 countries/territories
 * Alphabetically sorted by name
 */
export const COUNTRIES: Country[] = getCountryOptions().map(country => ({
  name: country.name,
  code: country.code,
}));

/**
 * Get all country names as a simple string array
 * Useful for simpler use cases where just names are needed
 */
export function getCountryNames(): string[] {
  return COUNTRIES.map(c => c.name);
}

/**
 * Search countries by query string
 * Performs case-insensitive search on country names
 *
 * @param query - Search query string
 * @returns Filtered array of countries matching the query
 */
export function searchCountries(query: string): Country[] {
  if (!query || query.trim().length === 0) {
    return COUNTRIES;
  }

  const normalizedQuery = query.toLowerCase().trim();

  return COUNTRIES.filter(country =>
    country.name.toLowerCase().includes(normalizedQuery) ||
    country.code.toLowerCase().includes(normalizedQuery)
  );
}

/**
 * Find a country by its exact name
 *
 * @param name - Country name to search for
 * @returns Country object if found, undefined otherwise
 */
export function findCountryByName(name: string): Country | undefined {
  return COUNTRIES.find(
    country => country.name.toLowerCase() === name.toLowerCase()
  );
}

/**
 * Find a country by its ISO code
 *
 * @param code - ISO 3166-1 alpha-2 country code
 * @returns Country object if found, undefined otherwise
 */
export function findCountryByCode(code: string): Country | undefined {
  return COUNTRIES.find(
    country => country.code.toLowerCase() === code.toLowerCase()
  );
}
