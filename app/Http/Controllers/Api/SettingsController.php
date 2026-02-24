<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\Settings\UpdateCloudonixSettingsRequest;
use App\Http\Requests\Settings\ValidateCloudonixRequest;
use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixClient;
use App\Services\Logging\AuditLogger;
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
     * Create a CloudonixClient with proper settings for the current organization.
     */
    private function getCloudonixClient(): CloudonixClient
    {
        $user = $this->getAuthenticatedUser();

        $settings = CloudonixSettings::where('organization_id', $user->organization_id)->first();
        if (! $settings) {
            throw new \RuntimeException('Cloudonix settings not configured');
        }

        return new CloudonixClient($settings);
    }

    /**
     * Get Cloudonix settings for the authenticated user's organization.
     */
    public function getCloudonixSettings(): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Check authorization using policy
        $this->authorize('viewAny', CloudonixSettings::class);

        $settings = CloudonixSettings::where('organization_id', $user->organization_id)->first();

        Log::info('Retrieved Cloudonix settings', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'has_settings' => $settings !== null,
        ]);

        if (! $settings) {
            // Generate URLs even without settings, using config app URL as fallback
            $baseUrl = rtrim(config('app.url'), '/');
            $callbackUrl = "{$baseUrl}/api/webhooks/cloudonix/session-update";
            $cdrUrl = "{$baseUrl}/api/webhooks/cloudonix/cdr";

            return response()->json([
                'settings' => null,
                'callback_url' => $callbackUrl,
                'cdr_url' => $cdrUrl,
            ]);
        }

        return response()->json([
            'settings' => [
                'id' => $settings->id,
                'organization_id' => $settings->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'domain_name' => $settings->domain_name,
                'domain_api_key' => $settings->getMaskedDomainApiKey(),
                'domain_requests_api_key' => $settings->getMaskedDomainRequestsApiKey(),
                'webhook_base_url' => $settings->webhook_base_url,
                'no_answer_timeout' => $settings->no_answer_timeout,
                'recording_format' => $settings->recording_format,
                'cloudonix_package' => $settings->cloudonix_package,
                'is_configured' => $settings->isConfigured(),
                'has_webhook_auth' => $settings->hasWebhookAuth(),
                'created_at' => $settings->created_at->toIso8601String(),
                'updated_at' => $settings->updated_at->toIso8601String(),
            ],
            'callback_url' => $settings->getCallbackUrl(),
            'cdr_url' => $settings->getCdrUrl(),
            'webhook_url_details' => $settings->getWebhookUrlDetails(),
        ]);
    }

    /**
     * Update Cloudonix settings for the authenticated user's organization.
     */
    public function updateCloudonixSettings(UpdateCloudonixSettingsRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $validated = $request->validated();

        Log::info('Updating Cloudonix settings', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'has_domain_uuid' => ! empty($validated['domain_uuid']),
            'has_domain_api_key' => ! empty($validated['domain_api_key']),
        ]);

        try {
            // Strip masked API key values to prevent overwriting real keys with masked strings.
            // The getCloudonixSettings and updateCloudonixSettings responses return masked keys
            // (e.g., "XI**...***xyz") — if the frontend sends these back unchanged, we must
            // not persist them. Only update keys when the user provides a new, unmasked value.
            foreach (['domain_api_key', 'domain_requests_api_key'] as $keyField) {
                if (isset($validated[$keyField]) && str_contains($validated[$keyField], '***')) {
                    unset($validated[$keyField]);
                }
            }

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

            // Add audit logging for Cloudonix settings update
            try {
                $changes = [];
                // Track which fields were changed (excluding sensitive fields like API keys)
                if (isset($validated['domain_uuid'])) {
                    $changes[] = 'domain_uuid';
                }
                if (isset($validated['domain_name'])) {
                    $changes[] = 'domain_name';
                }
                if (isset($validated['webhook_base_url'])) {
                    $changes[] = 'webhook_base_url';
                }
                if (isset($validated['no_answer_timeout'])) {
                    $changes[] = 'no_answer_timeout';
                }
                if (isset($validated['recording_format'])) {
                    $changes[] = 'recording_format';
                }

                AuditLogger::logCloudonixConfigUpdated($request, $changes);
            } catch (\Exception $auditException) {
                // Log audit failure but don't fail the operation
                Log::error('Failed to log Cloudonix settings update audit', [
                    'user_id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'error' => $auditException->getMessage(),
                ]);
            }

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

                $syncResult = $this->getCloudonixClient()->updateDomain(
                    $settings->domain_uuid,
                    $settings->domain_api_key,
                    $profileData
                );

                if (! $syncResult['success']) {
                    Log::warning('Failed to sync settings to Cloudonix', [
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'organization_id' => $user->organization_id,
                        'error' => $syncResult['message'],
                    ]);

                    $cloudonixSyncWarning = 'Settings saved locally, but failed to sync to Cloudonix: '.$syncResult['message'];
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
                    'domain_api_key' => $settings->getMaskedDomainApiKey(),
                    'domain_requests_api_key' => $settings->getMaskedDomainRequestsApiKey(),
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
     */
    public function validateCloudonixCredentials(ValidateCloudonixRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $validated = $request->validated();

        Log::info('Validating Cloudonix credentials', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'domain_uuid' => $validated['domain_uuid'],
        ]);

        try {
            // Handle masked API key: if the frontend sends a masked value (e.g., "XI12***abcd"),
            // fetch the real key from the database for validation.
            $apiKey = $validated['domain_api_key'];
            if (str_contains($apiKey, '***')) {
                $settings = CloudonixSettings::where('organization_id', $user->organization_id)->first();
                if ($settings && $settings->domain_api_key) {
                    $apiKey = $settings->domain_api_key;
                    Log::debug('Using stored API key for validation (masked value received)', [
                        'request_id' => $requestId,
                        'organization_id' => $user->organization_id,
                    ]);
                } else {
                    Log::warning('Masked API key received but no stored settings found', [
                        'request_id' => $requestId,
                        'organization_id' => $user->organization_id,
                    ]);

                    return response()->json([
                        'valid' => false,
                        'message' => 'Cannot validate with masked API key. Please provide the full API key or save settings first.',
                    ], 422);
                }
            }

            // Use static validation method (doesn't require client instantiation or existing settings)
            $result = CloudonixClient::validateDomainCredentials(
                $validated['domain_uuid'],
                $apiKey
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

                // Extract tenant billing package information
                if (isset($domainProfile['tenant']['billingPlan']['package']['name'])) {
                    $profileSettings['cloudonix_package'] = $domainProfile['tenant']['billingPlan']['package']['name'];
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
     */
    public function generateRequestsApiKey(): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

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
     * Get outbound voice trunks from Cloudonix API.
     */
    public function getOutboundTrunks(): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Check authorization using policy
        $this->authorize('viewAny', CloudonixSettings::class);

        $settings = CloudonixSettings::where('organization_id', $user->organization_id)->first();

        if (! $settings || ! $settings->isConfigured()) {
            return response()->json([
                'error' => 'Cloudonix settings not configured',
                'message' => 'Please configure Cloudonix settings before fetching outbound trunks.',
            ], 422);
        }

        Log::info('Fetching outbound voice trunks from Cloudonix', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'domain_uuid' => $settings->domain_uuid,
        ]);

        try {
            $client = new CloudonixClient($settings);
            $trunks = $client->listOutboundTrunks();

            if ($trunks === null) {
                Log::warning('Failed to fetch outbound trunks from Cloudonix API', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'domain_uuid' => $settings->domain_uuid,
                ]);

                return response()->json([
                    'error' => 'Failed to fetch outbound trunks',
                    'message' => 'Unable to retrieve outbound trunks from Cloudonix API.',
                ], 500);
            }

            Log::info('Successfully fetched outbound trunks from Cloudonix', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'trunk_count' => count($trunks),
            ]);

            return response()->json([
                'trunks' => $trunks,
                'count' => count($trunks),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception while fetching outbound trunks from Cloudonix', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to fetch outbound trunks',
                'message' => 'An error occurred while retrieving outbound trunks.',
            ], 500);
        }
    }

    /**
     * Generate a cryptographically secure random API key.
     *
     * Uses Laravel's Str::random() for cryptographically secure random generation.
     *
     * @param  int  $length  The length of the API key
     * @return string The generated API key
     */
    private function generateSecureApiKey(int $length = 32): string
    {
        return Str::random($length);
    }

    /**
     * Setup voice application for the organization.
     *
     * Orchestrates voice application creation/update and sets it as the domain's default.
     *
     * @param  CloudonixSettings  $settings  The Cloudonix settings
     * @param  string  $requestId  Request ID for logging
     * @param  int  $userId  User ID for logging
     * @param  int  $organizationId  Organization ID for logging
     * @return string|null Error message if failed, null on success
     */
    private function setupVoiceApplication(
        CloudonixSettings $settings,
        string $requestId,
        int $userId,
        int $organizationId
    ): ?string {
        try {
            $webhookUrl = $settings->getVoiceRouteUrl();

            Log::info('[VOICE_APP_SETUP] Starting voice application setup', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'webhook_base_url' => $settings->webhook_base_url,
                'computed_webhook_url' => $webhookUrl,
                'existing_app_id' => $settings->voice_application_id,
            ]);

            // Get or create voice application
            $result = $this->getOrCreateVoiceApplication($settings, $webhookUrl, $requestId, $userId, $organizationId);

            if ($result['error']) {
                return $result['error'];
            }

            $applicationId = $result['application_id'];
            $appData = $result['app_data'] ?? null;

            // Set as default application
            $defaultError = $this->setVoiceApplicationAsDefault(
                $settings,
                $applicationId,
                $requestId,
                $userId,
                $organizationId
            );

            if ($defaultError) {
                return $defaultError;
            }

            // Store application details if newly created
            if ($appData) {
                $this->storeVoiceApplicationDetails($settings, $appData, $requestId, $userId, $organizationId);
            }

            Log::info('[VOICE_APP_SETUP] Voice application setup completed successfully', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('[VOICE_APP_SETUP] Exception during voice application setup', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return 'Exception during voice application setup: '.$e->getMessage();
        }
    }

    /**
     * Get existing voice application or create a new one.
     *
     * @return array{application_id: int, app_data: array|null, error: string|null}
     */
    private function getOrCreateVoiceApplication(
        CloudonixSettings $settings,
        string $webhookUrl,
        string $requestId,
        int $userId,
        int $organizationId
    ): array {
        if (! empty($settings->voice_application_id)) {
            return $this->updateExistingVoiceApplication($settings, $webhookUrl, $requestId, $userId, $organizationId);
        }

        return $this->createNewVoiceApplication($settings, $webhookUrl, $requestId, $userId, $organizationId);
    }

    /**
     * Create a new voice application in Cloudonix.
     *
     * @return array{application_id: int, app_data: array|null, error: string|null}
     */
    private function createNewVoiceApplication(
        CloudonixSettings $settings,
        string $webhookUrl,
        string $requestId,
        int $userId,
        int $organizationId
    ): array {
        $appName = 'opbx-routing-application-'.Str::random(8);

        Log::info('[VOICE_APP_SETUP] Creating new voice application', [
            'request_id' => $requestId,
            'application_name' => $appName,
            'webhook_url' => $webhookUrl,
        ]);

        $payload = [
            'name' => $appName,
            'type' => 'cxml',
            'url' => $webhookUrl,
            'method' => 'POST',
            'profile' => new \stdClass,
        ];

        $result = $this->getCloudonixClient()->createVoiceApplication(
            $settings->domain_uuid,
            $settings->domain_api_key,
            $payload
        );

        if (! $result['success']) {
            Log::error('[VOICE_APP_SETUP] Voice application creation failed', [
                'request_id' => $requestId,
                'error' => $result['message'],
            ]);

            return [
                'application_id' => 0,
                'app_data' => null,
                'error' => 'Failed to create voice application: '.($result['message'] ?? 'Unknown error'),
            ];
        }

        Log::info('[VOICE_APP_SETUP] Voice application created successfully', [
            'request_id' => $requestId,
            'application_id' => $result['data']['id'],
        ]);

        return [
            'application_id' => $result['data']['id'],
            'app_data' => $result['data'],
            'error' => null,
        ];
    }

    /**
     * Update existing voice application URL if needed.
     *
     * @return array{application_id: int, app_data: null, error: string|null}
     */
    private function updateExistingVoiceApplication(
        CloudonixSettings $settings,
        string $webhookUrl,
        string $requestId,
        int $userId,
        int $organizationId
    ): array {
        $applicationId = $settings->voice_application_id;

        Log::info('[VOICE_APP_SETUP] Updating existing voice application URL', [
            'request_id' => $requestId,
            'application_id' => $applicationId,
            'webhook_url' => $webhookUrl,
        ]);

        $result = $this->getCloudonixClient()->updateVoiceApplication(
            $settings->domain_uuid,
            $settings->domain_api_key,
            $applicationId,
            [
                'url' => $webhookUrl,
                'method' => 'POST',
                'profile' => new \stdClass,
            ]
        );

        if (! $result['success']) {
            // Log warning but don't fail - app might still work with old URL
            Log::warning('[VOICE_APP_SETUP] Failed to update voice application URL', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
                'error' => $result['message'],
            ]);
        } else {
            Log::info('[VOICE_APP_SETUP] Voice application URL updated successfully', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
            ]);
        }

        return [
            'application_id' => $applicationId,
            'app_data' => null,
            'error' => null,
        ];
    }

    /**
     * Set voice application as the domain's default.
     *
     * @return string|null Error message if failed, null on success
     */
    private function setVoiceApplicationAsDefault(
        CloudonixSettings $settings,
        int $applicationId,
        string $requestId,
        int $userId,
        int $organizationId
    ): ?string {
        Log::info('[VOICE_APP_SETUP] Setting default application for domain', [
            'request_id' => $requestId,
            'application_id' => $applicationId,
        ]);

        $result = $this->getCloudonixClient()->updateDomainDefaultApplication(
            $settings->domain_uuid,
            $settings->domain_api_key,
            $applicationId
        );

        if (! $result['success']) {
            Log::error('[VOICE_APP_SETUP] Failed to set default application', [
                'request_id' => $requestId,
                'application_id' => $applicationId,
                'error' => $result['message'],
            ]);

            return 'Failed to set default application: '.($result['message'] ?? 'Unknown error');
        }

        Log::info('[VOICE_APP_SETUP] Default application set successfully', [
            'request_id' => $requestId,
            'application_id' => $applicationId,
        ]);

        return null;
    }

    /**
     * Store voice application details in database.
     */
    private function storeVoiceApplicationDetails(
        CloudonixSettings $settings,
        array $appData,
        string $requestId,
        int $userId,
        int $organizationId
    ): void {
        Log::info('[VOICE_APP_SETUP] Storing application details in database', [
            'request_id' => $requestId,
            'application_id' => $appData['id'],
        ]);

        $settings->update([
            'voice_application_id' => $appData['id'],
            'voice_application_uuid' => $appData['uuid'] ?? null,
            'voice_application_name' => $appData['name'] ?? null,
        ]);

        Log::info('[VOICE_APP_SETUP] Voice application details stored successfully', [
            'request_id' => $requestId,
            'application_id' => $appData['id'],
        ]);
    }
}
