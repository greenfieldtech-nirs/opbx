<?php

declare(strict_types=1);

namespace App\Services\Cloudonix;

use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CloudonixVoiceService
{
    public function __construct(
        private CloudonixClient $client,
        private LanguageMapper $languageMapper
    ) {}

    /**
     * Get voices with caching
     */
    public function getVoices(CloudonixSettings $settings, string $requestId): array
    {
        // Cache key based on domain UUID
        $cacheKey = "cloudonix-voices:{$settings->domain_uuid}";

        // Cache for 1 hour (3600 seconds)
        return Cache::remember($cacheKey, 3600, function () use ($settings, $requestId) {
            return $this->fetchAndNormalizeVoices($settings, $requestId);
        });
    }

    /**
     * Fetch and normalize voices from Cloudonix API
     */
    private function fetchAndNormalizeVoices(CloudonixSettings $settings, string $requestId): array
    {
        try {
            Log::info('Fetching voices from Cloudonix API', [
                'request_id' => $requestId,
                'domain_uuid' => $settings->domain_uuid
            ]);

            $voices = $this->client->getVoices($settings->domain_uuid);

            if (empty($voices)) {
                throw new \RuntimeException('Cloudonix API returned empty voices list');
            }

            return $this->normalizeVoices($voices, $requestId);

        } catch (\RuntimeException $e) {
            // Re-throw runtime exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            // Convert other exceptions to runtime exceptions with appropriate messages
            $message = $e->getMessage();

            if (str_contains($message, 'token') || str_contains($message, 'unauthorized') || str_contains($message, 'authentication')) {
                throw new \RuntimeException('Authentication failed - invalid API token');
            } elseif (str_contains($message, 'timeout') || str_contains($message, 'connection') || str_contains($message, 'network')) {
                throw new \RuntimeException('Unable to connect to Cloudonix API');
            } else {
                throw new \RuntimeException('Cloudonix API error: ' . $message);
            }
        }
    }

    /**
     * Normalize voice data structure
     */
    private function normalizeVoices(array $voices, string $requestId): array
    {
        // Normalize the response format to match our VoiceOption interface
        $normalizedVoices = array_map(function ($voice) use ($requestId) {
            // Ensure required fields exist
            if (!isset($voice['voice'])) {
                Log::warning('Skipping invalid voice entry missing voice field', [
                    'request_id' => $requestId,
                    'voice_data' => $voice
                ]);
                return null; // Skip invalid voices
            }

            // Parse voice identifier (e.g., "AWS:Maxim" -> provider="AWS", name="Maxim")
            $voiceParts = explode(':', $voice['voice'], 2);
            $provider = $voiceParts[0] ?? '';
            $name = $voiceParts[1] ?? $voice['voice'];

            return [
                'id' => $voice['voice'], // The full voice identifier for CXML, e.g., "AWS:Maxim"
                'name' => $name, // Just the voice name part
                'provider' => $voice['provider'] ?? '', // e.g., "Polly", "Google", "Azure"
                'language' => isset($voice['languages']) && is_array($voice['languages']) && !empty($voice['languages']) ? $voice['languages'][0] : 'en-US', // Primary language
                'gender' => $voice['gender'] ?? 'neutral',
                'premium' => ($voice['pricing'] ?? 'standard') === 'premium',
                'pricing' => $voice['pricing'] ?? 'standard'
            ];
        }, $voices);

        // Filter out null entries (invalid voices)
        $normalizedVoices = array_filter($normalizedVoices, function ($voice) {
            return $voice !== null;
        });

        // Sort by language, then gender, then name
        usort($normalizedVoices, function ($a, $b) {
            // Primary sort: language
            $aLang = $a['language'] ?? 'zz';
            $bLang = $b['language'] ?? 'zz';
            $langCompare = strcmp($aLang, $bLang);
            if ($langCompare !== 0) {
                return $langCompare;
            }

            // Secondary sort: gender (female before male)
            $genderOrder = ['female' => 1, 'male' => 2, 'neutral' => 3];
            $aGender = isset($a['gender']) ? ($genderOrder[$a['gender']] ?? 4) : 4;
            $bGender = isset($b['gender']) ? ($genderOrder[$b['gender']] ?? 4) : 4;
            if ($aGender !== $bGender) {
                return $aGender - $bGender;
            }

            // Tertiary sort: name
            $aName = $a['name'] ?? '';
            $bName = $b['name'] ?? '';
            return strcmp($aName, $bName);
        });

        Log::info('Successfully normalized voices', [
            'request_id' => $requestId,
            'total_voices' => count($normalizedVoices)
        ]);

        return $normalizedVoices;
    }

    /**
     * Extract filter options from voices
     */
    public function extractFilterOptions(array $voices): array
    {
        $languages = [];
        $genders = [];
        $providers = [];
        $pricing = [];

        foreach ($voices as $voice) {
            // Extract language info
            $languageCode = $voice['language'];
            $languageName = $this->languageMapper->getLanguageName($languageCode);

            if (!isset($languages[$languageCode])) {
                $languages[$languageCode] = [
                    'code' => $languageCode,
                    'name' => $languageName
                ];
            }

            // Extract other filter options
            $genders[$voice['gender']] = $voice['gender'];
            $providers[$voice['provider']] = $voice['provider'];
            $pricing[$voice['pricing']] = $voice['pricing'];
        }

        // Sort languages by name
        usort($languages, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        // Sort other filters
        ksort($genders);
        ksort($providers);
        ksort($pricing);

        return [
            'languages' => array_values($languages),
            'genders' => array_values($genders),
            'providers' => array_values($providers),
            'pricing' => array_values($pricing)
        ];
    }
}