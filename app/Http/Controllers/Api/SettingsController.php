<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCloudonixSettingsRequest;
use App\Http\Requests\Settings\ValidateCloudonixRequest;
use App\Models\CloudonixSettings;
use App\Services\CloudonixApiClient;
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
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly CloudonixApiClient $cloudonixClient
    ) {}

    /**
     * Get Cloudonix settings for the authenticated user's organization.
     *
     * @return JsonResponse
     */
    public function getCloudonixSettings(): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization
        if (!$user->role->canManageOrganization()) {
            Log::warning('Unauthorized access to Cloudonix settings', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'role' => $user->role->value,
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only organization owners can view Cloudonix settings.',
            ], 403);
        }

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
                'domain_api_key' => $settings->domain_api_key, // Show real key (owner only)
                'domain_requests_api_key' => $settings->domain_requests_api_key, // Show real key (owner only)
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
        $requestId = (string) Str::uuid();
        $user = $request->user();

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
                }
            }

            $response = [
                'message' => $cloudonixSyncWarning ?? 'Cloudonix settings updated successfully.',
                'settings' => [
                    'id' => $settings->id,
                    'organization_id' => $settings->organization_id,
                    'domain_uuid' => $settings->domain_uuid,
                    'domain_api_key' => $settings->domain_api_key, // Show real key (owner only)
                    'domain_requests_api_key' => $settings->domain_requests_api_key, // Show real key (owner only)
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
        $requestId = (string) Str::uuid();
        $user = $request->user();

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
        $requestId = (string) Str::uuid();
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization
        if (!$user->role->canManageOrganization()) {
            Log::warning('Unauthorized API key generation attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'role' => $user->role->value,
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only organization owners can generate API keys.',
            ], 403);
        }

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
}
