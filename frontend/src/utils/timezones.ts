/**
 * Timezone Constants and Utilities
 *
 * Provides a comprehensive list of common timezones with UTC offsets
 */

export interface TimezoneOption {
  value: string;
  label: string;
  offset: string;
  region: string;
}

/**
 * Common timezones grouped by region
 */
export const TIMEZONES: TimezoneOption[] = [
  // Americas
  { value: 'America/New_York', label: 'Eastern Time (New York)', offset: 'UTC-5:00', region: 'Americas' },
  { value: 'America/Chicago', label: 'Central Time (Chicago)', offset: 'UTC-6:00', region: 'Americas' },
  { value: 'America/Denver', label: 'Mountain Time (Denver)', offset: 'UTC-7:00', region: 'Americas' },
  { value: 'America/Phoenix', label: 'Mountain Time - Arizona (Phoenix)', offset: 'UTC-7:00', region: 'Americas' },
  { value: 'America/Los_Angeles', label: 'Pacific Time (Los Angeles)', offset: 'UTC-8:00', region: 'Americas' },
  { value: 'America/Anchorage', label: 'Alaska Time (Anchorage)', offset: 'UTC-9:00', region: 'Americas' },
  { value: 'Pacific/Honolulu', label: 'Hawaii Time (Honolulu)', offset: 'UTC-10:00', region: 'Pacific' },
  { value: 'America/Toronto', label: 'Eastern Time (Toronto)', offset: 'UTC-5:00', region: 'Americas' },
  { value: 'America/Vancouver', label: 'Pacific Time (Vancouver)', offset: 'UTC-8:00', region: 'Americas' },
  { value: 'America/Mexico_City', label: 'Central Time (Mexico City)', offset: 'UTC-6:00', region: 'Americas' },
  { value: 'America/Sao_Paulo', label: 'Brasília Time (São Paulo)', offset: 'UTC-3:00', region: 'Americas' },
  { value: 'America/Buenos_Aires', label: 'Argentina Time (Buenos Aires)', offset: 'UTC-3:00', region: 'Americas' },
  { value: 'America/Santiago', label: 'Chile Time (Santiago)', offset: 'UTC-4:00', region: 'Americas' },
  { value: 'America/Lima', label: 'Peru Time (Lima)', offset: 'UTC-5:00', region: 'Americas' },

  // Europe
  { value: 'Europe/London', label: 'Greenwich Mean Time (London)', offset: 'UTC+0:00', region: 'Europe' },
  { value: 'Europe/Dublin', label: 'Greenwich Mean Time (Dublin)', offset: 'UTC+0:00', region: 'Europe' },
  { value: 'Europe/Paris', label: 'Central European Time (Paris)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Berlin', label: 'Central European Time (Berlin)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Madrid', label: 'Central European Time (Madrid)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Rome', label: 'Central European Time (Rome)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Amsterdam', label: 'Central European Time (Amsterdam)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Brussels', label: 'Central European Time (Brussels)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Vienna', label: 'Central European Time (Vienna)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Zurich', label: 'Central European Time (Zurich)', offset: 'UTC+1:00', region: 'Europe' },
  { value: 'Europe/Athens', label: 'Eastern European Time (Athens)', offset: 'UTC+2:00', region: 'Europe' },
  { value: 'Europe/Bucharest', label: 'Eastern European Time (Bucharest)', offset: 'UTC+2:00', region: 'Europe' },
  { value: 'Europe/Helsinki', label: 'Eastern European Time (Helsinki)', offset: 'UTC+2:00', region: 'Europe' },
  { value: 'Europe/Istanbul', label: 'Turkey Time (Istanbul)', offset: 'UTC+3:00', region: 'Europe' },
  { value: 'Europe/Moscow', label: 'Moscow Time (Moscow)', offset: 'UTC+3:00', region: 'Europe' },

  // Asia
  { value: 'Asia/Dubai', label: 'Gulf Standard Time (Dubai)', offset: 'UTC+4:00', region: 'Asia' },
  { value: 'Asia/Karachi', label: 'Pakistan Time (Karachi)', offset: 'UTC+5:00', region: 'Asia' },
  { value: 'Asia/Kolkata', label: 'India Standard Time (Kolkata)', offset: 'UTC+5:30', region: 'Asia' },
  { value: 'Asia/Dhaka', label: 'Bangladesh Time (Dhaka)', offset: 'UTC+6:00', region: 'Asia' },
  { value: 'Asia/Bangkok', label: 'Indochina Time (Bangkok)', offset: 'UTC+7:00', region: 'Asia' },
  { value: 'Asia/Singapore', label: 'Singapore Time (Singapore)', offset: 'UTC+8:00', region: 'Asia' },
  { value: 'Asia/Hong_Kong', label: 'Hong Kong Time (Hong Kong)', offset: 'UTC+8:00', region: 'Asia' },
  { value: 'Asia/Shanghai', label: 'China Standard Time (Shanghai)', offset: 'UTC+8:00', region: 'Asia' },
  { value: 'Asia/Tokyo', label: 'Japan Standard Time (Tokyo)', offset: 'UTC+9:00', region: 'Asia' },
  { value: 'Asia/Seoul', label: 'Korea Standard Time (Seoul)', offset: 'UTC+9:00', region: 'Asia' },
  { value: 'Asia/Jakarta', label: 'Western Indonesia Time (Jakarta)', offset: 'UTC+7:00', region: 'Asia' },
  { value: 'Asia/Manila', label: 'Philippines Time (Manila)', offset: 'UTC+8:00', region: 'Asia' },
  { value: 'Asia/Taipei', label: 'Taipei Time (Taipei)', offset: 'UTC+8:00', region: 'Asia' },
  { value: 'Asia/Jerusalem', label: 'Israel Time (Jerusalem)', offset: 'UTC+2:00', region: 'Asia' },
  { value: 'Asia/Riyadh', label: 'Arabia Standard Time (Riyadh)', offset: 'UTC+3:00', region: 'Asia' },

  // Africa
  { value: 'Africa/Cairo', label: 'Eastern European Time (Cairo)', offset: 'UTC+2:00', region: 'Africa' },
  { value: 'Africa/Johannesburg', label: 'South Africa Time (Johannesburg)', offset: 'UTC+2:00', region: 'Africa' },
  { value: 'Africa/Lagos', label: 'West Africa Time (Lagos)', offset: 'UTC+1:00', region: 'Africa' },
  { value: 'Africa/Nairobi', label: 'East Africa Time (Nairobi)', offset: 'UTC+3:00', region: 'Africa' },

  // Australia & Pacific
  { value: 'Australia/Sydney', label: 'Australian Eastern Time (Sydney)', offset: 'UTC+10:00', region: 'Australia' },
  { value: 'Australia/Melbourne', label: 'Australian Eastern Time (Melbourne)', offset: 'UTC+10:00', region: 'Australia' },
  { value: 'Australia/Brisbane', label: 'Australian Eastern Time (Brisbane)', offset: 'UTC+10:00', region: 'Australia' },
  { value: 'Australia/Perth', label: 'Australian Western Time (Perth)', offset: 'UTC+8:00', region: 'Australia' },
  { value: 'Australia/Adelaide', label: 'Australian Central Time (Adelaide)', offset: 'UTC+9:30', region: 'Australia' },
  { value: 'Pacific/Auckland', label: 'New Zealand Time (Auckland)', offset: 'UTC+12:00', region: 'Pacific' },
  { value: 'Pacific/Fiji', label: 'Fiji Time (Fiji)', offset: 'UTC+12:00', region: 'Pacific' },

  // UTC
  { value: 'UTC', label: 'Coordinated Universal Time (UTC)', offset: 'UTC+0:00', region: 'UTC' },
];

/**
 * Get timezone groups organized by region
 */
export function getTimezonesByRegion(): Record<string, TimezoneOption[]> {
  const grouped: Record<string, TimezoneOption[]> = {};

  TIMEZONES.forEach((tz) => {
    if (!grouped[tz.region]) {
      grouped[tz.region] = [];
    }
    grouped[tz.region].push(tz);
  });

  return grouped;
}

/**
 * Find a timezone by value
 */
export function findTimezone(value: string): TimezoneOption | undefined {
  return TIMEZONES.find((tz) => tz.value === value);
}

/**
 * Format timezone label with offset
 */
export function formatTimezoneLabel(tz: TimezoneOption): string {
  return `${tz.label} (${tz.offset})`;
}
