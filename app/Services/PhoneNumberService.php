<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PhoneNumberService
{
    /**
     * Extract calling code from a phone number.
     * Supports E.164 format numbers starting with + and calling codes without +.
     *
     * @param string $phoneNumber
     * @return string|null The calling code (e.g., "+1", "+44") or null if not found
     */
    public function extractCallingCode(string $phoneNumber): ?string
    {
        // Remove any non-digit characters except +
        $cleanedNumber = preg_replace('/[^\d+]/', '', $phoneNumber);

        // If number starts with +, process as E.164 format
        if (str_starts_with($cleanedNumber, '+')) {
            $digits = substr($cleanedNumber, 1);

            // Try different calling code lengths (4-1 digits) - longest first
            for ($length = 4; $length >= 1; $length--) {
                $potentialCode = substr($digits, 0, $length);
                $callingCode = '+' . $potentialCode;
                if ($this->isValidCallingCode($callingCode) && $this->callingCodeToCountryCode($callingCode) !== null) {
                    return $callingCode;
                }
            }
        } else {
            // Number doesn't start with +, try to extract calling code from beginning
            // Try different calling code lengths (4-1 digits) - longest first
            for ($length = 4; $length >= 1; $length--) {
                $potentialCode = substr($cleanedNumber, 0, $length);
                $callingCode = '+' . $potentialCode;
                if ($this->isValidCallingCode($callingCode) && $this->callingCodeToCountryCode($callingCode) !== null) {
                    return $callingCode;
                }
            }
        }

        return null;
    }

    /**
     * Convert calling code to country code.
     * Uses a mapping of common calling codes to ISO country codes.
     *
     * @param string $callingCode
     * @return string|null The ISO country code or null if not found
     */
    public function callingCodeToCountryCode(string $callingCode): ?string
    {
        $countryCodes = [
            '+1' => 'US',
            '+7' => 'RU',
            '+20' => 'EG',
            '+27' => 'ZA',
            '+30' => 'GR',
            '+31' => 'NL',
            '+32' => 'BE',
            '+33' => 'FR',
            '+34' => 'ES',
            '+36' => 'HU',
            '+39' => 'IT',
            '+40' => 'RO',
            '+41' => 'CH',
            '+43' => 'AT',
            '+44' => 'GB',
            '+45' => 'DK',
            '+46' => 'SE',
            '+47' => 'NO',
            '+48' => 'PL',
            '+49' => 'DE',
            '+51' => 'PE',
            '+52' => 'MX',
            '+53' => 'CU',
            '+54' => 'AR',
            '+55' => 'BR',
            '+56' => 'CL',
            '+57' => 'CO',
            '+58' => 'VE',
            '+60' => 'MY',
            '+61' => 'AU',
            '+62' => 'ID',
            '+63' => 'PH',
            '+64' => 'NZ',
            '+65' => 'SG',
            '+66' => 'TH',
            '+81' => 'JP',
            '+82' => 'KR',
            '+84' => 'VN',
            '+86' => 'CN',
            '+90' => 'TR',
            '+91' => 'IN',
            '+92' => 'PK',
            '+93' => 'AF',
            '+94' => 'LK',
            '+95' => 'MM',
            '+98' => 'IR',
            '+212' => 'MA',
            '+213' => 'DZ',
            '+216' => 'TN',
            '+218' => 'LY',
            '+220' => 'GM',
            '+221' => 'SN',
            '+222' => 'MR',
            '+223' => 'ML',
            '+224' => 'GN',
            '+225' => 'CI',
            '+226' => 'BF',
            '+227' => 'NE',
            '+228' => 'TG',
            '+229' => 'BJ',
            '+230' => 'MU',
            '+231' => 'LR',
            '+232' => 'SL',
            '+233' => 'GH',
            '+234' => 'NG',
            '+235' => 'TD',
            '+236' => 'CF',
            '+237' => 'CM',
            '+238' => 'CV',
            '+239' => 'ST',
            '+240' => 'GQ',
            '+241' => 'GA',
            '+242' => 'CG',
            '+243' => 'CD',
            '+244' => 'AO',
            '+245' => 'GW',
            '+246' => 'IO',
            '+247' => 'AC',
            '+248' => 'SC',
            '+249' => 'SD',
            '+250' => 'RW',
            '+251' => 'ET',
            '+252' => 'SO',
            '+253' => 'DJ',
            '+254' => 'KE',
            '+255' => 'TZ',
            '+256' => 'UG',
            '+257' => 'BI',
            '+258' => 'MZ',
            '+260' => 'ZM',
            '+261' => 'MG',
            '+262' => 'RE',
            '+263' => 'ZW',
            '+264' => 'NA',
            '+265' => 'MW',
            '+266' => 'LS',
            '+267' => 'BW',
            '+268' => 'SZ',
            '+269' => 'KM',
            '+290' => 'SH',
            '+291' => 'ER',
            '+297' => 'AW',
            '+298' => 'FO',
            '+299' => 'GL',
            '+350' => 'GI',
            '+351' => 'PT',
            '+352' => 'LU',
            '+353' => 'IE',
            '+354' => 'IS',
            '+355' => 'AL',
            '+356' => 'MT',
            '+357' => 'CY',
            '+358' => 'FI',
            '+359' => 'BG',
            '+370' => 'LT',
            '+371' => 'LV',
            '+372' => 'EE',
            '+373' => 'MD',
            '+374' => 'AM',
            '+375' => 'BY',
            '+376' => 'AD',
            '+377' => 'MC',
            '+378' => 'SM',
            '+380' => 'UA',
            '+381' => 'RS',
            '+382' => 'ME',
            '+383' => 'XK',
            '+385' => 'HR',
            '+386' => 'SI',
            '+387' => 'BA',
            '+389' => 'MK',
            '+420' => 'CZ',
            '+421' => 'SK',
            '+423' => 'LI',
            '+500' => 'FK',
            '+501' => 'BZ',
            '+502' => 'GT',
            '+503' => 'SV',
            '+504' => 'HN',
            '+505' => 'NI',
            '+506' => 'CR',
            '+507' => 'PA',
            '+508' => 'PM',
            '+509' => 'HT',
            '+590' => 'GP',
            '+591' => 'BO',
            '+592' => 'GY',
            '+593' => 'EC',
            '+594' => 'GF',
            '+595' => 'PY',
            '+596' => 'MQ',
            '+597' => 'SR',
            '+598' => 'UY',
            '+599' => 'CW',
            '+670' => 'TL',
            '+672' => 'AQ',
            '+673' => 'BN',
            '+674' => 'NR',
            '+675' => 'PG',
            '+676' => 'TO',
            '+677' => 'SB',
            '+678' => 'VU',
            '+679' => 'FJ',
            '+680' => 'PW',
            '+681' => 'WF',
            '+682' => 'CK',
            '+683' => 'NU',
            '+684' => 'AS',
            '+685' => 'WS',
            '+686' => 'KI',
            '+687' => 'NC',
            '+688' => 'TV',
            '+689' => 'PF',
            '+690' => 'TK',
            '+691' => 'FM',
            '+692' => 'MH',
            '+850' => 'KP',
            '+852' => 'HK',
            '+853' => 'MO',
            '+855' => 'KH',
            '+856' => 'LA',
            '+880' => 'BD',
            '+886' => 'TW',
            '+960' => 'MV',
            '+961' => 'LB',
            '+962' => 'JO',
            '+963' => 'SY',
            '+964' => 'IQ',
            '+965' => 'KW',
            '+966' => 'SA',
            '+967' => 'YE',
            '+968' => 'OM',
            '+970' => 'PS',
            '+971' => 'AE',
            '+972' => 'IL',
            '+973' => 'BH',
            '+974' => 'QA',
            '+975' => 'BT',
            '+976' => 'MN',
            '+977' => 'NP',
            '+992' => 'TJ',
            '+993' => 'TM',
            '+994' => 'AZ',
            '+995' => 'GE',
            '+996' => 'KG',
            '+998' => 'UZ',
        ];

        return $countryCodes[$callingCode] ?? null;
    }

    /**
     * Check if a calling code is valid.
     *
     * @param string $callingCode
     * @return bool
     */
    private function isValidCallingCode(string $callingCode): bool
    {
        // Basic validation - starts with + and followed by digits
        return preg_match('/^\+\d+$/', $callingCode) === 1;
    }
}