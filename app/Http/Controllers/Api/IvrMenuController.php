<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\StoreIvrMenuRequest;
use App\Http\Requests\UpdateIvrMenuRequest;
use App\Models\CloudonixSettings;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IVR Menus management API controller.
 *
 * Handles CRUD operations for IVR menus within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class IvrMenuController extends Controller
{
    use ApiRequestHandler;

    /**
     * Get available TTS voices for IVR menus.
     *
     * @return JsonResponse
     */
    public function getVoices(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();

            // Get organization Cloudonix settings
            $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $user->organization_id)->first();

            if (!$cloudonixSettings) {
                Log::error('Cloudonix settings missing for organization', [
                    'organization_id' => $user->organization_id
                ]);

                return response()->json([
                    'error' => 'Cloudonix settings not configured for your organization.',
                    'troubleshooting' => [
                        'Contact your system administrator',
                        'Ensure Cloudonix integration is properly set up for your organization'
                    ]
                ], 503);
            }

            if (!$cloudonixSettings->domain_uuid || !$cloudonixSettings->domain_api_key) {
                Log::error('Incomplete Cloudonix settings', [
                    'organization_id' => $user->organization_id,
                    'has_domain_uuid' => !empty($cloudonixSettings->domain_uuid),
                    'has_api_key' => !empty($cloudonixSettings->domain_api_key)
                ]);

                return response()->json([
                    'error' => 'Cloudonix settings are incomplete.',
                    'troubleshooting' => [
                        'Contact your system administrator',
                        'Ensure domain UUID and API key are configured in organization settings'
                    ]
                ], 503);
            }

            // Fetch voices from Cloudonix API with caching
            try {
                $voices = \Illuminate\Support\Facades\Cache::remember(
                    "cloudonix-voices:{$cloudonixSettings->domain_uuid}",
                    3600, // 1 hour cache
                    function () use ($cloudonixSettings) {
                        return $this->fetchVoicesFromCloudonix($cloudonixSettings->domain_uuid, $cloudonixSettings);
                    }
                );

                if (empty($voices)) {
                    Log::error('Cloudonix API returned empty voice list', [
                        'organization_id' => $user->organization_id,
                        'domain_uuid' => $cloudonixSettings->domain_uuid
                    ]);

                    return response()->json([
                        'error' => 'No voices available from Cloudonix API.',
                        'troubleshooting' => [
                            'Check Cloudonix account status',
                            'Verify API permissions include voice access',
                            'Contact Cloudonix support if issue persists'
                        ]
                    ], 502);
                }

                // Extract unique filter options
                $filters = $this->extractFilterOptions($voices);

                return response()->json([
                    'data' => $voices,
                    'filters' => $filters
                ]);

            } catch (\RuntimeException $e) {
                // Handle specific CloudonixClient errors
                $errorMessage = $e->getMessage();

                if (str_contains($errorMessage, 'token') || str_contains($errorMessage, 'unauthorized') || str_contains($errorMessage, 'authentication')) {
                    $statusCode = 401; // Unauthorized
                    $userMessage = 'Authentication failed with Cloudonix API.';
                    $troubleshooting = [
                        'Check API token validity',
                        'Regenerate API key in Cloudonix dashboard',
                        'Update organization settings with new token'
                    ];
                } elseif (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'connection') || str_contains($errorMessage, 'network')) {
                    $statusCode = 502; // Bad Gateway
                    $userMessage = 'Unable to connect to Cloudonix API.';
                    $troubleshooting = [
                        'Check network connectivity',
                        'Verify Cloudonix API is accessible',
                        'Try again in a few minutes'
                    ];
                } else {
                    $statusCode = 502; // Bad Gateway
                    $userMessage = 'Cloudonix API error: ' . $errorMessage;
                    $troubleshooting = [
                        'Check Cloudonix service status',
                        'Contact Cloudonix support if issue persists'
                    ];
                }

                Log::error('Cloudonix API error in getVoices', [
                    'organization_id' => $user->organization_id,
                    'domain_uuid' => $cloudonixSettings->domain_uuid,
                    'error' => $errorMessage,
                    'status_code' => $statusCode
                ]);

                return response()->json([
                    'error' => $userMessage,
                    'troubleshooting' => $troubleshooting
                ], $statusCode);
            }

        } catch (\Exception $e) {
            Log::error('Unexpected error in getVoices', [
                'error' => $e->getMessage(),
                'organization_id' => $user->organization_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred while fetching voices.',
                'troubleshooting' => [
                    'Contact system administrator',
                    'Check application logs for details'
                ]
            ], 500);
        }
    }

    private function fetchVoicesFromCloudonix(string $domainUuid, \App\Models\CloudonixSettings $cloudonixSettings): array
    {
        $client = new \App\Services\CloudonixClient\CloudonixClient($cloudonixSettings);

        try {
            $voices = $client->getVoices($domainUuid);

            if (empty($voices)) {
                throw new \RuntimeException('Cloudonix API returned empty voices list');
            }

            $voiceData = $voices;

            // Normalize the response format to match our VoiceOption interface
            $normalizedVoices = array_map(function ($voice) {
                // Ensure required fields exist
                if (!isset($voice['voice'])) {
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
            }, $voiceData);

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

            return $normalizedVoices;

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
     * Extract unique filter options from voices data
     */
    private function extractFilterOptions(array $voices): array
    {
        $languages = [];
        $genders = [];
        $providers = [];
        $pricing = [];

        foreach ($voices as $voice) {
            // Extract language info
            $languageCode = $voice['language'];
            $languageName = $this->formatLanguageName($languageCode);

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

    /**
     * Format language name for display
     * Convert identifiers like "cs-CZ-Chirp3-HD-Achernar" to "Chirp3 Achernar"
     */
    private function formatLanguageName(string $languageCode): string
    {
        // Complete language mapping with proper names
        $languageMap = [
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

        return $languageMap[$languageCode] ?? $languageCode;
    }

    private function formatVoiceDisplayName(array $voice): string
    {
        $provider = $voice['provider'] ?? '';
        $voiceName = $voice['voice'] ?? '';

        // Extract voice name from the full identifier (e.g., "AWS-Neural:Gabrielle" -> "Gabrielle")
        $parts = explode(':', $voiceName);
        $shortName = end($parts);

        // Format as "Provider: VoiceName (Language)"
        $language = isset($voice['languages']) && is_array($voice['languages']) ? $voice['languages'][0] : '';
        $gender = $voice['gender'] ?? '';

        $displayParts = [];
        if ($provider) {
            $displayParts[] = ucfirst($provider);
        }
        if ($shortName) {
            $displayParts[] = $shortName;
        }
        if ($language) {
            $displayParts[] = "({$language})";
        }
        if ($gender && $gender !== 'neutral') {
            $displayParts[] = ucfirst($gender);
        }

        return implode(' ', $displayParts);
    }



    /**
     * Display a paginated list of IVR menus.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        Log::info('Retrieving IVR menus list', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        // Build query with eager loading
        // For dropdown requests (per_page=100), don't load options to improve performance
        $isDropdownRequest = $request->input('per_page') == 100;

        $query = IvrMenu::query()
            ->forOrganization($user->organization_id);

        if (!$isDropdownRequest) {
            $query->with([
                'options' => function ($query) {
                    $query->select('id', 'ivr_menu_id', 'input_digits', 'description', 'destination_type', 'destination_id', 'priority')
                        ->orderBy('priority', 'asc');
                },
            ])
            ->withCount('options');
        }

        // Apply filters
        if ($request->has('status')) {
            $status = IvrMenuStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');

        // Validate sort field
        $allowedSortFields = ['name', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'desc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 25);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $ivrMenus = $query->paginate($perPage);

        Log::info('IVR menus list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $ivrMenus->total(),
            'per_page' => $perPage,
        ]);

        return response()->json([
            'data' => $ivrMenus->items(),
            'meta' => [
                'current_page' => $ivrMenus->currentPage(),
                'per_page' => $ivrMenus->perPage(),
                'total' => $ivrMenus->total(),
                'last_page' => $ivrMenus->lastPage(),
                'from' => $ivrMenus->firstItem(),
                'to' => $ivrMenus->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created IVR menu.
     *
     * @param StoreIvrMenuRequest $request
     * @return JsonResponse
     */
    public function store(StoreIvrMenuRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $validated = $request->validated();

        Log::info('Creating new IVR menu', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_name' => $validated['name'],
        ]);

        try {
            $ivrMenu = DB::transaction(function () use ($user, $validated): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

                // Assign to current user's organization
                $validated['organization_id'] = $user->organization_id;

                // Resolve audio file path from recording ID if provided
                $this->resolveAudioFilePath($validated);

                // Ensure only one audio source is active
                $this->clearUnusedAudioSource($validated);

                // Create IVR menu
                $ivrMenu = IvrMenu::create($validated);

                // Create IVR menu options
                foreach ($optionsData as $optionData) {
                    IvrMenuOption::create([
                        'ivr_menu_id' => $ivrMenu->id,
                        'input_digits' => $optionData['input_digits'],
                        'description' => $optionData['description'] ?? null,
                        'destination_type' => $optionData['destination_type'],
                        'destination_id' => $optionData['destination_id'],
                        'priority' => $optionData['priority'],
                    ]);
                }

                return $ivrMenu;
            });

            // Load relationships
            $ivrMenu->load('options');

            Log::info('IVR menu created successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
            ]);

            return response()->json([
                'message' => 'IVR menu created successfully.',
                'data' => $ivrMenu,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create IVR menu', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create IVR menu',
                'message' => 'An error occurred while creating the IVR menu.',
            ], 500);
        }
    }

    /**
     * Display the specified IVR menu.
     *
     * @param Request $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function show(Request $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        // Load relationships
        $ivrMenu->load('options');

        Log::info('IVR menu details retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_id' => $ivrMenu->id,
        ]);

        return response()->json([
            'data' => $ivrMenu,
        ]);
    }

    /**
     * Update the specified IVR menu.
     *
     * @param UpdateIvrMenuRequest $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function update(UpdateIvrMenuRequest $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        $validated = $request->validated();

        Log::info('Updating IVR menu', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_id' => $ivrMenu->id,
            'ivr_menu_name' => $validated['name'],
        ]);

        try {
            $ivrMenu = DB::transaction(function () use ($ivrMenu, $validated): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

                // Resolve audio file path from recording ID if provided
                $this->resolveAudioFilePath($validated);

                // Ensure only one audio source is active
                $this->clearUnusedAudioSource($validated);

                // Update IVR menu
                $ivrMenu->update($validated);

                // Delete existing options and create new ones
                $ivrMenu->options()->delete();

                // Create new options
                foreach ($optionsData as $optionData) {
                    IvrMenuOption::create([
                        'ivr_menu_id' => $ivrMenu->id,
                        'input_digits' => $optionData['input_digits'],
                        'description' => $optionData['description'] ?? null,
                        'destination_type' => $optionData['destination_type'],
                        'destination_id' => $optionData['destination_id'],
                        'priority' => $optionData['priority'],
                    ]);
                }

                return $ivrMenu;
            });

            // Load relationships
            $ivrMenu->load('options');

            Log::info('IVR menu updated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
            ]);

            return response()->json([
                'message' => 'IVR menu updated successfully.',
                'data' => $ivrMenu,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update IVR menu', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update IVR menu',
                'message' => 'An error occurred while updating the IVR menu.',
            ], 500);
        }
    }

    /**
     * Remove the specified IVR menu.
     *
     * @param Request $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function destroy(Request $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        // Check if IVR menu is referenced by other IVR menus
        $referencingMenus = DB::table('ivr_menu_options')
            ->join('ivr_menus', 'ivr_menu_options.ivr_menu_id', '=', 'ivr_menus.id')
            ->where('ivr_menu_options.destination_type', 'ivr_menu')
            ->where('ivr_menu_options.destination_id', $ivrMenu->id)
            ->where('ivr_menus.organization_id', $user->organization_id)
            ->select('ivr_menus.id', 'ivr_menus.name')
            ->distinct()
            ->get();

        // Check if IVR menu is used as failover in other menus
        $failoverMenus = IvrMenu::where('organization_id', $user->organization_id)
            ->where('failover_destination_type', 'ivr_menu')
            ->where('failover_destination_id', $ivrMenu->id)
            ->where('id', '!=', $ivrMenu->id)
            ->select('id', 'name')
            ->get();

        // Check if IVR menu is referenced by DID routing
        $referencingDids = DB::table('did_numbers')
            ->where('routing_type', 'ivr_menu')
            ->where('routing_config->ivr_menu_id', $ivrMenu->id)
            ->where('organization_id', $user->organization_id)
            ->select('id', 'phone_number')
            ->get();

        $hasReferences = $referencingMenus->isNotEmpty() || $failoverMenus->isNotEmpty() || $referencingDids->isNotEmpty();

        if ($hasReferences) {
            $references = [];

            if ($referencingMenus->isNotEmpty()) {
                $references['ivr_menus'] = $referencingMenus->map(fn($menu) => [
                    'id' => $menu->id,
                    'name' => $menu->name,
                ])->toArray();
            }

            if ($failoverMenus->isNotEmpty()) {
                $references['failover_menus'] = $failoverMenus->map(fn($menu) => [
                    'id' => $menu->id,
                    'name' => $menu->name,
                ])->toArray();
            }

            if ($referencingDids->isNotEmpty()) {
                $references['phone_numbers'] = $referencingDids->map(fn($did) => [
                    'id' => $did->id,
                    'phone_number' => $did->phone_number,
                ])->toArray();
            }

            return response()->json([
                'error' => 'Cannot delete IVR menu',
                'message' => 'This IVR menu is being used and cannot be deleted. Please remove all references first.',
                'references' => $references,
            ], 409);
        }

        $ivrMenuName = $ivrMenu->name;

        try {
            $ivrMenu->delete();

            Log::info('IVR menu deleted successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenuName,
            ]);

            return response()->json([
                'message' => 'IVR menu deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete IVR menu', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete IVR menu',
                'message' => 'An error occurred while deleting the IVR menu.',
            ], 500);
        }
    }

    /**
     * Resolve the audio file path from either a direct URL or a recording ID.
     * If recording_id is provided or audio_file_path contains a recording ID, look up the recording and get its playback URL.
     *
     * @param array $data
     * @return void
     */
    private function resolveAudioFilePath(array &$data): void
    {
        $recordingId = isset($data['recording_id']) ? $data['recording_id'] : null;
        $audioFilePath = isset($data['audio_file_path']) ? $data['audio_file_path'] : null;

        // Check if recording_id is provided
        if ($recordingId) {
            $recording = \App\Models\Recording::find($recordingId);
        }
        // Check if audio_file_path is a recording ID (integer or numeric string)
        elseif ($audioFilePath && (is_int($audioFilePath) || (is_string($audioFilePath) && ctype_digit($audioFilePath)))) {
            $recording = \App\Models\Recording::find((int) $audioFilePath);
        }

        if (isset($recording) && $recording && $recording->isActive()) {
            // Get the authenticated user ID for generating the playback URL
            $user = auth()->user();
            if ($user) {
                $data['audio_file_path'] = $recording->getPlaybackUrl($user->id);
            }
        }

        // Remove the recording_id from the data as it's not stored in the IVR menu
        if (isset($data['recording_id'])) {
            unset($data['recording_id']);
        }
    }

    /**
     * Clear the unused audio source to ensure only one audio configuration is active.
     * If TTS text is provided, clear audio file path, and vice versa.
     *
     * @param array $data
     * @return void
     */
    private function clearUnusedAudioSource(array &$data): void
    {
        $audioFilePath = isset($data['audio_file_path']) ? $data['audio_file_path'] : null;
        $ttsText = isset($data['tts_text']) ? $data['tts_text'] : null;

        // If TTS text is provided, ensure audio file path is cleared
        if (!empty($ttsText)) {
            $data['audio_file_path'] = null;
        }
        // If audio file path is provided, ensure TTS text is cleared
        elseif (!empty($audioFilePath)) {
            $data['tts_text'] = null;
            $data['tts_voice'] = null; // Also clear TTS voice when not using TTS
        }
    }
}