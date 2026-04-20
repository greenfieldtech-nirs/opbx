<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles AMD (Answering Machine Detection) action decisions from the AMD worker.
 *
 * Receives detection results and executes the configured action:
 * - URL: Switch the call to a new voice application URL
 * - HANGUP: Disconnect the session
 * - CONTINUE: Do nothing (just log)
 */
class AmdActionController extends Controller
{
    /**
     * Handle AMD detection result and execute the configured action.
     */
    public function handle(Request $request): JsonResponse
    {
        // Validate Bearer token from AMD worker
        $authHeader = $request->header('Authorization', '');
        $expectedToken = config('services.amd_worker.api_token', env('AMD_WORKER_API_TOKEN', ''));
        if (! str_starts_with($authHeader, 'Bearer ') || ! hash_equals('Bearer '.$expectedToken, $authHeader)) {
            Log::warning('AMD action: Unauthorized request', [
                'ip' => $request->ip(),
                'auth_present' => ! empty($authHeader),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'callSid' => ['required', 'string'],
            'streamSid' => ['required', 'string'],
            'session' => ['required', 'string'],
            'result' => ['required', 'string', 'in:voicemail,human,unknown'],
            'action' => ['required', 'string'],
            'confidence' => ['nullable', 'numeric'],
            'detectionTimeMs' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
        ]);

        $callSid = $validated['callSid'];
        $sessionToken = $validated['session'];
        $result = $validated['result'];
        $action = $validated['action'];
        $confidence = $validated['confidence'] ?? null;
        $detectionTimeMs = $validated['detectionTimeMs'] ?? null;
        $reason = $validated['reason'] ?? null;

        Log::info('AMD action received', [
            'call_sid' => $callSid,
            'session_token' => $sessionToken,
            'result' => $result,
            'action' => $action,
            'confidence' => $confidence,
            'detection_time_ms' => $detectionTimeMs,
            'reason' => $reason,
        ]);

        // Find Cloudonix settings for this domain
        // We need to look up by session token or call SID to find the organization
        $settings = $this->resolveSettings($sessionToken, $callSid);

        if (! $settings) {
            Log::warning('AMD action: No Cloudonix settings found', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No Cloudonix settings found for this session',
            ], 404);
        }

        try {
            $client = new CloudonixClient($settings);

            // Step 1: Update session profile with AMD result BEFORE executing action
            $profile = [
                'amd' => [
                    'result' => $result,
                    'confidence' => $confidence,
                    'detectionTimeMs' => $detectionTimeMs,
                    'reason' => $reason,
                    'timestamp' => now()->toIso8601String(),
                ],
            ];

            $profileUpdated = $client->updateSessionProfile($sessionToken, $profile);
            Log::info('AMD action: Session profile updated', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
                'profile' => $profile,
                'success' => $profileUpdated,
            ]);

            // Step 2: Execute the configured action
            if ($action === 'HANGUP') {
                $success = $client->disconnectSession($sessionToken);
                Log::info('AMD action: HANGUP executed', [
                    'call_sid' => $callSid,
                    'session_token' => $sessionToken,
                    'success' => $success,
                ]);

                return response()->json([
                    'status' => $success ? 'ok' : 'error',
                    'action' => 'hangup',
                    'profile_updated' => $profileUpdated,
                ]);
            }

            if (str_starts_with($action, 'http://') || str_starts_with($action, 'https://')) {
                $success = $client->switchVoiceApplication($sessionToken, $action);
                Log::info('AMD action: URL transfer executed', [
                    'call_sid' => $callSid,
                    'session_token' => $sessionToken,
                    'url' => $action,
                    'success' => $success,
                ]);

                return response()->json([
                    'status' => $success ? 'ok' : 'error',
                    'action' => 'transfer',
                    'url' => $action,
                    'profile_updated' => $profileUpdated,
                ]);
            }

            if ($action === 'CONTINUE') {
                Log::info('AMD action: CONTINUE — no action taken', [
                    'call_sid' => $callSid,
                    'session_token' => $sessionToken,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'action' => 'continue',
                    'profile_updated' => $profileUpdated,
                ]);
            }

            Log::warning('AMD action: Unknown action type', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
                'action' => $action,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => "Unknown action: {$action}",
            ], 400);
        } catch (\Exception $e) {
            Log::error('AMD action: Exception while executing action', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve Cloudonix settings from session token.
     *
     * Looks up the auto-dialer session by Cloudonix session token,
     * finds the campaign's organization, and returns that organization's
     * Cloudonix settings. The session token is the authoritative identifier
     * from Cloudonix — never use call_id.
     */
    private function resolveSettings(string $sessionToken, string $callSid): ?CloudonixSettings
    {
        // Look up the auto-dialer session by Cloudonix session token
        // Must bypass OrganizationScope since this is an unauthenticated worker callback
        $session = \App\Scopes\OrganizationScope::bypass(
            fn () => \App\Models\AutoDialerCallSession::where('session_token', $sessionToken)->first()
        );

        if (! $session) {
            Log::warning('AMD action: No auto-dialer session found for token', [
                'session_token' => $sessionToken,
                'call_sid' => $callSid,
            ]);

            return null;
        }

        // Load the campaign with its organization
        // Must bypass OrganizationScope since this is an unauthenticated worker callback
        $campaign = \App\Scopes\OrganizationScope::bypass(
            fn () => \App\Models\AutoDialerCampaign::with('organization.cloudonixSettings')
                ->find($session->campaign_id)
        );

        if (! $campaign || ! $campaign->organization) {
            Log::warning('AMD action: Campaign or organization not found', [
                'session_token' => $sessionToken,
                'campaign_id' => $session->campaign_id,
            ]);

            return null;
        }

        $settings = $campaign->organization->cloudonixSettings;

        if (! $settings) {
            Log::warning('AMD action: No Cloudonix settings for organization', [
                'session_token' => $sessionToken,
                'organization_id' => $campaign->organization_id,
            ]);

            return null;
        }

        Log::info('AMD action: Resolved Cloudonix settings', [
            'session_token' => $sessionToken,
            'organization_id' => $campaign->organization_id,
            'domain_uuid' => $settings->domain_uuid,
        ]);

        return $settings;
    }
}
