<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;


use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\ConferenceRoom\StoreConferenceRoomRequest;
use App\Http\Requests\Settings\ValidateCloudonixRequest;
use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Settings management API controller.
 *
 * Handles organization-level settings including Cloudonix integration.
 */
class SettingsController extends Controller
{
    use ApiRequestHandler;
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly CloudonixClient $cloudonixClient
    ) {
    }

    /**
     * Get Cloudonix settings for the authenticated user's organization.
     *
     * @return JsonResponse
     */
    public function getCloudonixSettings(): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization using policy
        $this->authorize('viewAny', CloudonixSettings::class);

        $settings = CloudonixSettings::where('organization_id', $user->organization_id)->first();

        Log::info('Retrieved Cloudonix settings', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'has_settings' => $settings !== null,
        ]);

        if (!$settings) {
            return response()->json([
                'settings' => null,
                'callback_url' => (new CloudonixSettings())->getCallbackUrl(),
                'cdr_url' => (new CloudonixSettings())->getCdrUrl(),
            ]);
        }

        return response()->json([
            'settings' => [
                'id' => $settings->id,
                'organization_id' => $settings->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'domain_name' => $settings->domain_name,
                'domain_api_key' => $settings->domain_api_key, // Show real key (owner only)
                'domain_requests_api_key' => $settings->domain_requests_api_key, // Show real key (owner only)
                'webhook_base_url' => $settings->webhook_base_url,
                'no_answer_timeout' => $settings->no_answer_timeout,
                'recording_format' => $settings->recording_format,
                'is_configured' => $settings->isConfigured(),
                'has_webhook_auth' => $settings->hasWebhookAuth(),
                'created_at' => $settings->created_at->toIso8601String(),
                'updated_at' => $settings->updated_at->toIso8601String(),
            ],
            'callback_url' => $settings->getCallbackUrl(),
            'cdr_url' => $settings->getCdrUrl(),
        ]);
    }

    /**
     * Update Cloudonix settings for the authenticated user's organization.
     *
     * @param UpdateCloudonixSettingsRequest $request
     * @return JsonResponse
     */
    public function updateCloudonixSettings(UpdateCloudonixSettingsRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Updating Cloudonix settings', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'has_domain_uuid' => !empty($validated['domain_uuid']),
            'has_domain_api_key' => !empty($validated['domain_api_key']),
        ]);

        try {
            // Save settings to local database
            $settings = DB::transaction(function () use ($user, $validated): CloudonixSettings {
                $settings = CloudonixSettings::updateOrCreate(
                    ['organization_id' => $user->organization_id],
                    array_merge(['organization_id' => $user->organization_id], $validated)
                );

                return $settings;
            });

            Log::info('Cloudonix settings saved to local database', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'settings_id' => $settings->id,
            ]);

            // Sync settings to Cloudonix if credentials are configured
            $cloudonixSyncWarning = null;
            if ($settings->isConfigured()) {
                // Map local field names to Cloudonix profile field names
                $profileData = [
                    'call-timeout' => $settings->no_answer_timeout,
                    'recording-media-type' => $settings->recording_format,
                ];

                // Add callback URL if available
                $callbackUrl = $settings->getCallbackUrl();
                if ($callbackUrl) {
                    $profileData['session-update-endpoint'] = $callbackUrl;
                }

                // Add requests API key if available (authorization for webhook callbacks)
                if ($settings->domain_requests_api_key) {
                    $profileData['authorization-api-key'] = $settings->domain_requests_api_key;
                }

                // Add CDR endpoint (auto-generated from app URL)
                $cdrUrl = $settings->getCdrUrl();
                if ($cdrUrl) {
                    $profileData['cdr-endpoint'] = $cdrUrl;
                }

                $syncResult = $this->cloudonixClient->updateDomain(
                    $settings->domain_uuid,
                    $settings->domain_api_key,
                    $profileData
                );

                if (!$syncResult['success']) {
                    Log::warning('Failed to sync settings to Cloudonix', [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'organization_id' => $user->organization_id,
                        'error' => $syncResult['message'],
                    ]);

                    $cloudonixSyncWarning = 'Settings saved locally, but failed to sync to Cloudonix: ' . $syncResult['message'];
                } else {
                    Log::info('Settings synced to Cloudonix successfully', [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'organization_id' => $user->organization_id,
                    ]);

                    // Create or update voice application after successful domain sync
                    $voiceAppError = $this->setupVoiceApplication($settings, $requestId, $user->id, $user->organization_id);

                    if ($voiceAppError) {
                        // Voice application setup failed - add to warning message
                        $cloudonixSyncWarning = $voiceAppError;

                        Log::warning('Voice application setup failed but settings were saved', [
                            'request_id' => $requestId,
                            'user_id' => $user->id,
                            'organization_id' => $user->organization_id,
                            'error' => $voiceAppError,
                        ]);
                    }
                }
            }

            $response = [
                'message' => $cloudonixSyncWarning ?? 'Cloudonix settings updated successfully.',
                'settings' => [
                    'id' => $settings->id,
                    'organization_id' => $settings->organization_id,
                    'domain_uuid' => $settings->domain_uuid,
                    'domain_name' => $settings->domain_name,
                    'domain_api_key' => $settings->domain_api_key, // Show real key (owner only)
                    'domain_requests_api_key' => $settings->domain_requests_api_key, // Show real key (owner only)
                    'webhook_base_url' => $settings->webhook_base_url,
                    'no_answer_timeout' => $settings->no_answer_timeout,
                    'recording_format' => $settings->recording_format,
                    'is_configured' => $settings->isConfigured(),
                    'has_webhook_auth' => $settings->hasWebhookAuth(),
                    'created_at' => $settings->created_at->toIso8601String(),
                    'updated_at' => $settings->updated_at->toIso8601String(),
                ],
                'callback_url' => $settings->getCallbackUrl(),
                'cdr_url' => $settings->getCdrUrl(),
            ];

            if ($cloudonixSyncWarning) {
                $response['warning'] = $cloudonixSyncWarning;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to update Cloudonix settings', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update settings',
                'message' => 'An error occurred while updating Cloudonix settings.',
            ], 500);
        }
    }

    /**
     * Validate Cloudonix domain credentials without saving.
     *
     * @param ValidateCloudonixRequest $request
     * @return JsonResponse
     */
    public function validateCloudonixCredentials(ValidateCloudonixRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Validating Cloudonix credentials', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'domain_uuid' => $validated['domain_uuid'],
        ]);

        try {
            $result = $this->cloudonixClient->validateDomain(
                $validated['domain_uuid'],
                $validated['domain_api_key']
            );

            $isValid = $result['valid'] ?? false;
            $domainProfile = $result['profile'] ?? null;

            // Extract settings from domain profile if available
            $profileSettings = [];
            if ($domainProfile) {
                // Extract domain name (e.g., "sample.cloudonix.net")
                if (isset($domainProfile['domain'])) {
                    $profileSettings['domain_name'] = $domainProfile['domain'];
                }

                // Extract call-timeout (default to 60 if not present)
                if (isset($domainProfile['call-timeout'])) {
                    $timeout = (int) $domainProfile['call-timeout'];
                    if ($timeout >= 5 && $timeout <= 120) {
                        $profileSettings['no_answer_timeout'] = $timeout;
                    }
                }

                // Extract recording-media-type (default to mp3 if not present)
                if (isset($domainProfile['recording-media-type'])) {
                    $format = strtolower($domainProfile['recording-media-type']);
                    if (in_array($format, ['wav', 'mp3'], true)) {
                        $profileSettings['recording_format'] = $format;
                    }
                }
            }

            Log::info('Cloudonix credentials validation result', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'domain_uuid' => $validated['domain_uuid'],
                'is_valid' => $isValid,
                'has_profile' => $domainProfile !== null,
                'profile_settings' => $profileSettings,
            ]);

            if ($isValid) {
                return response()->json([
                    'valid' => true,
                    'message' => 'Cloudonix credentials are valid.',
                    'profile_settings' => $profileSettings,
                ]);
            }

            return response()->json([
                'valid' => false,
                'message' => 'Invalid Cloudonix credentials. Please check your domain UUID and API key.',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Cloudonix credentials validation error', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'valid' => false,
                'message' => 'An error occurred while validating credentials.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a secure random API key for webhook authentication.
     *
     * @return JsonResponse
     */
    public function generateRequestsApiKey(): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization using policy
        $this->authorize('generateApiKey', CloudonixSettings::class);

        // Generate a cryptographically secure random key
        // 32 characters: alphanumeric + symbols for high entropy
        $apiKey = $this->generateSecureApiKey(32);

        Log::info('Generated webhook API key', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'api_key' => $apiKey,
            'message' => 'API key generated successfully. Copy and save this key as it cannot be retrieved later.',
        ]);
    }

    /**
     * Generate a cryptographically secure random API key.
     *
     * @param int $length The length of the API key
     * @return string The generated API key
     */
    private function generateSecureApiKey(int $length = 32): string
    {
        // Use a mix of alphanumeric characters and symbols for high entropy
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_+-=[]{}|;:,.<>?';
        $charactersLength = strlen($characters);
        $apiKey = '';

        // Generate random bytes and convert to characters
        $randomBytes = random_bytes($length);

        for ($i = 0; $i < $length; $i++) {
            $apiKey .= $characters[ord($randomBytes[$i]) % $charactersLength];
        }

        return $apiKey;
    }

    /**
     * Setup voice application for the organization.
     *
     * Creates a new voice application or updates existing one, then sets it as the domain's default application.
     *
     * @param CloudonixSettings $settings The Cloudonix settings
     * @param string $requestId Request ID for logging
     * @param int $userId User ID for logging
     * @param int $organizationId Organization ID for logging
     * @return void
     */
    private function setupVoiceApplication(
        CloudonixSettings $settings,
        string $requestId,
        int $userId,
        int $organizationId
    ): ?string {
        try {
            // Generate webhook URL for voice routing using webhook_base_url
            $baseUrl = !empty($settings->webhook_base_url)
                ? rtrim($settings->webhook_base_url, '/')
                : rtrim(config('app.url'), '/');
            $webhookUrl = "{$baseUrl}/api/voice/route";

            Log::info('[VOICE_APP_SETUP] Starting voice application setup', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'webhook_base_url' => $settings->webhook_base_url,
                'computed_webhook_url' => $webhookUrl,
                'existing_app_id' => $settings->voice_application_id,
            ]);

            // Check if we already have an application
            $shouldCreateNew = empty($settings->voice_application_id);

            if ($shouldCreateNew) {
                // Create new application with unique name
                $appName = 'opbx-routing-application-' . Str::random(8);

                Log::info('[VOICE_APP_SETUP] Creating new voice application', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'application_name' => $appName,
                    'webhook_url' => $webhookUrl,
                ]);

                // Create application payload with proper JSON object for profile
                $applicationPayload = [
                    'name' => $appName,
                    'type' => 'cxml',
                    'url' => $webhookUrl,
                    'method' => 'POST',
                    'profile' => new \stdClass(), // Empty object, not array
                ];

                Log::info('[VOICE_APP_SETUP] Application payload prepared', [
                    'request_id' => $requestId,
                    'payload' => $applicationPayload,
                    'payload_json' => json_encode($applicationPayload),
                ]);

                $appResult = $this->cloudonixClient->createVoiceApplication(
                    $settings->domain_uuid,
                    $settings->domain_api_key,
                    $applicationPayload
                );

                if (!$appResult['success']) {
                    $errorMessage = 'Failed to create voice application: ' . ($appResult['message'] ?? 'Unknown error');

                    Log::error('[VOICE_APP_SETUP] Voice application creation failed', [
                        'request_id' => $requestId,
                        'user_id' => $userId,
                        'organization_id' => $organizationId,
                        'error' => $appResult['message'],
                        'response_data' => $appResult['data'] ?? null,
                    ]);

                    return $errorMessage;
                }

                $appData = $appResult['data'];
                $applicationId = $appData['id'];

                Log::info('[VOICE_APP_SETUP] Voice application created successfully', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'application_id' => $applicationId,
                    'application_uuid' => $appData['uuid'] ?? null,
                    'application_name' => $appData['name'] ?? null,
                    'full_response' => $appData,
                ]);
            } else {
                // Use existing application but check if URL needs updating
                $applicationId = $settings->voice_application_id;

                // Generate expected webhook URL for comparison
                $expectedWebhookUrl = "{$baseUrl}/api/voice/route";

                Log::info('[VOICE_APP_SETUP] Using existing voice application - checking if URL update needed', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'application_id' => $applicationId,
                    'expected_webhook_url' => $expectedWebhookUrl,
                ]);

                // Check if we need to update the application URL
                // We can't easily check the current URL without an API call, so we'll update it proactively
                // when webhook_base_url changes (which would change the expected URL)
                $updateResult = $this->cloudonixClient->updateVoiceApplication(
                    $settings->domain_uuid,
                    $settings->domain_api_key,
                    $applicationId,
                    [
                        'url' => $expectedWebhookUrl,
                        'method' => 'POST',
                        'profile' => new \stdClass(), // Ensure profile is an object
                    ]
                );

                if (!$updateResult['success']) {
                    Log::warning('[VOICE_APP_SETUP] Failed to update voice application URL', [
                        'request_id' => $requestId,
                        'user_id' => $userId,
                        'organization_id' => $organizationId,
                        'application_id' => $applicationId,
                        'expected_url' => $expectedWebhookUrl,
                        'error' => $updateResult['message'],
                    ]);

                    // Don't fail the whole process for URL update issues
                    // The application might still work with the old URL
                } else {
                    Log::info('[VOICE_APP_SETUP] Voice application URL updated successfully', [
                        'request_id' => $requestId,
                        'user_id' => $userId,
                        'organization_id' => $organizationId,
                        'application_id' => $applicationId,
                        'new_url' => $expectedWebhookUrl,
                    ]);
                }
            }

            Log::info('[VOICE_APP_SETUP] Setting default application for domain', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
                'domain_uuid' => $settings->domain_uuid,
            ]);

            // Set as default application for the domain
            $defaultAppResult = $this->cloudonixClient->updateDomainDefaultApplication(
                $settings->domain_uuid,
                $settings->domain_api_key,
                $applicationId
            );

            if (!$defaultAppResult['success']) {
                $errorMessage = 'Failed to set default application: ' . ($defaultAppResult['message'] ?? 'Unknown error');

                Log::error('[VOICE_APP_SETUP] Failed to set default application', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'application_id' => $applicationId,
                    'error' => $defaultAppResult['message'],
                    'response_data' => $defaultAppResult['data'] ?? null,
                ]);

                return $errorMessage;
            }

            Log::info('[VOICE_APP_SETUP] Default application set successfully', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'application_id' => $applicationId,
            ]);

            // Store application details if we created a new one
            if ($shouldCreateNew && isset($appData)) {
                Log::info('[VOICE_APP_SETUP] Storing application details in database', [
                    'request_id' => $requestId,
                    'application_id' => $appData['id'],
                    'application_uuid' => $appData['uuid'] ?? null,
                    'application_name' => $appData['name'] ?? null,
                ]);

                $settings->update([
                    'voice_application_id' => $appData['id'],
                    'voice_application_uuid' => $appData['uuid'] ?? null,
                    'voice_application_name' => $appData['name'] ?? null,
                ]);

                Log::info('[VOICE_APP_SETUP] Voice application details stored successfully', [
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'application_id' => $appData['id'],
                ]);
            }

            Log::info('[VOICE_APP_SETUP] Voice application setup completed successfully', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
            ]);

            return null; // Success - no error
        } catch (\Exception $e) {
            $errorMessage = 'Exception during voice application setup: ' . $e->getMessage();

            Log::error('[VOICE_APP_SETUP] Exception during voice application setup', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return $errorMessage;
        }
    }
}
