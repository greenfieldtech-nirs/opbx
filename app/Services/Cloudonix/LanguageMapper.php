<?php

declare(strict_types=1);

namespace App\Services\Cloudonix;

class LanguageMapper
{
    /**
     * Map language codes to readable names
     */
    private const LANGUAGE_MAP = [
        "af-ZA"     => "Afrikaans (South Africa)",
        "am-ET"     => "Amharic (Ethiopia)",
        "ar-AE"     => "Arabic (United Arab Emirates)",
        "ar-XA"     => "Arabic (Ext. or Pseudo-locales)",
        "arb"       => "Standard Arabic",
        "bg-BG"     => "Bulgarian (Bulgaria)",
        "bn-IN"     => "Bengali (India)",
        "ca-ES"     => "Catalan (Spain)",
        "cmn-CN"    => "Mandarin Chinese (China)",
        "cmn-TW"    => "Mandarin Chinese (Taiwan)",
        "cs-CZ"     => "Czech (Czech Republic)",
        "cy-GB"     => "Welsh (United Kingdom)",
        "da-DK"     => "Danish (Denmark)",
        "de-AT"     => "German (Austria)",
        "de-CH"     => "German (Switzerland)",
        "de-DE"     => "German (Germany)",
        "el-GR"     => "Greek (Greece)",
        "en-AU"     => "English (Australia)",
        "en-GB"     => "English (United Kingdom)",
        "en-GB-WLS" => "English (United Kingdom – Wales)",
        "en-IE"     => "English (Ireland)",
        "en-IN"     => "English (India)",
        "en-NZ"     => "English (New Zealand)",
        "en-SG"     => "English (Singapore)",
        "en-US"     => "English (United States)",
        "en-ZA"     => "English (South Africa)",
        "es-ES"     => "Spanish (Spain)",
        "es-MX"     => "Spanish (Mexico)",
        "es-US"     => "Spanish (United States)",
        "et-EE"     => "Estonian (Estonia)",
        "eu-ES"     => "Basque (Spain)",
        "fi-FI"     => "Finnish (Finland)",
        "fil-PH"    => "Filipino (Philippines)",
        "fr-BE"     => "French (Belgium)",
        "fr-CA"     => "French (Canada)",
        "fr-FR"     => "French (France)",
        "gl-ES"     => "Galician (Spain)",
        "gu-IN"     => "Gujarati (India)",
        "he-IL"     => "Hebrew (Israel)",
        "hi-IN"     => "Hindi (India)",
        "hr-HR"     => "Croatian (Croatia)",
        "hu-HU"     => "Hungarian (Hungary)",
        "id-ID"     => "Indonesian (Indonesia)",
        "is-IS"     => "Icelandic (Iceland)",
        "it-IT"     => "Italian (Italy)",
        "ja-JP"     => "Japanese (Japan)",
        "kn-IN"     => "Kannada (India)",
        "ko-KR"     => "Korean (South Korea)",
        "lt-LT"     => "Lithuanian (Lithuania)",
        "lv-LV"     => "Latvian (Latvia)",
        "ml-IN"     => "Malayalam (India)",
        "mr-IN"     => "Marathi (India)",
        "ms-MY"     => "Malay (Malaysia)",
        "nb-NO"     => "Norwegian Bokmål (Norway)",
        "nl-BE"     => "Dutch (Belgium)",
        "nl-NL"     => "Dutch (Netherlands)",
        "pa-IN"     => "Punjabi (India)",
        "pl-PL"     => "Polish (Poland)",
        "pt-BR"     => "Portuguese (Brazil)",
        "pt-PT"     => "Portuguese (Portugal)",
        "ro-RO"     => "Romanian (Romania)",
        "ru-RU"     => "Russian (Russia)",
        "sk-SK"     => "Slovak (Slovakia)",
        "sl-SI"     => "Slovenian (Slovenia)",
        "sr-RS"     => "Serbian (Serbia)",
        "sv-SE"     => "Swedish (Sweden)",
        "sw-KE"     => "Swahili (Kenya)",
        "ta-IN"     => "Tamil (India)",
        "te-IN"     => "Telugu (India)",
        "th-TH"     => "Thai (Thailand)",
        "tr-TR"     => "Turkish (Turkey)",
        "uk-UA"     => "Ukrainian (Ukraine)",
        "ur-IN"     => "Urdu (India)",
        "vi-VN"     => "Vietnamese (Vietnam)",
        "yue-CN"    => "Cantonese (China)",
        "yue-HK"    => "Cantonese (Hong Kong)"
    ];

    public function getLanguageName(string $code): string
    {
        return self::LANGUAGE_MAP[$code] ?? $code;
    }

    public function getAllLanguageNames(): array
    {
        return self::LANGUAGE_MAP;
    }
}