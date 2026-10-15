<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints for the AMD (Answering Machine Detection) worker.
 *
 * - session(): the worker asks, on stream start, whether the stream's session
 *   token is a known active auto-dialer call session; unknown streams are closed.
 * - handle(): receives a detection result and executes the action configured on
 *   the session's campaign (HANGUP or CONTINUE; empty means HANGUP). The action is never taken from
 *   the request: anyone who could open a stream would otherwise control the call.
 */
class AmdActionController extends Controller
{
    /**
     * Is this session token a known, active (non-terminated) auto-dialer call?
     */
    public function session(Request $request): JsonResponse
    {
        if ($unauthorized = $this->rejectUnauthorized($request)) {
            return $unauthorized;
        }

        $validated = $request->validate(['session' => ['required', 'string', 'max:255']]);

        $active = OrganizationScope::bypass(
            fn (): bool => AutoDialerCallSession::active()
                ->where('session_token', $validated['session'])
                ->exists()
        );

        if (! $active) {
            Log::warning('AMD session check: unknown or inactive session', [
                'session_token_prefix' => substr($validated['session'], 0, 8),
                'ip' => $request->ip(),
            ]);

            return response()->json(['active' => false], 404);
        }

        return response()->json(['active' => true]);
    }

    /**
     * Handle AMD detection result and execute the campaign's configured action.
     */
    public function handle(Request $request): JsonResponse
    {
        if ($unauthorized = $this->rejectUnauthorized($request)) {
            return $unauthorized;
        }

        $validated = $request->validate([
            'callSid' => ['required', 'string'],
            'streamSid' => ['required', 'string'],
            'session' => ['required', 'string'],
            'result' => ['required', 'string', 'in:voicemail,human,unknown'],
            // Still sent by the worker; ignored (see class docblock).
            'action' => ['nullable', 'string'],
            'confidence' => ['nullable', 'numeric'],
            'detectionTimeMs' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
        ]);

        $callSid = $validated['callSid'];
        $sessionToken = $validated['session'];
        $result = $validated['result'];
        $confidence = $validated['confidence'] ?? null;
        $detectionTimeMs = $validated['detectionTimeMs'] ?? null;
        $reason = $validated['reason'] ?? null;

        // Worker callback: no authenticated user, so the tenant scope must be bypassed.
        [$session, $campaign] = OrganizationScope::bypass(function () use ($sessionToken): array {
            $session = AutoDialerCallSession::where('session_token', $sessionToken)->first();
            $campaign = $session
                ? AutoDialerCampaign::with('organization.cloudonixSettings')->find($session->campaign_id)
                : null;

            return [$session, $campaign];
        });

        $settings = $campaign?->organization?->cloudonixSettings;
        if (! $session || ! $settings) {
            Log::warning('AMD action: no session, campaign or Cloudonix settings for token', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
                'session_found' => $session !== null,
                'campaign_id' => $session?->campaign_id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No Cloudonix settings found for this session',
            ], 404);
        }

        // An empty action is HANGUP (what the campaign form shows for it); only an explicit CONTINUE keeps the call.
        $action = $campaign->{'action_'.$result} ?: 'HANGUP';

        Log::info('AMD action received', [
            'call_sid' => $callSid,
            'session_token' => $sessionToken,
            'organization_id' => $campaign->organization_id,
            'result' => $result,
            'action' => $action,
            'confidence' => $confidence,
            'detection_time_ms' => $detectionTimeMs,
            'reason' => $reason,
        ]);

        try {
            // Update LOCAL session record first (source of truth for CDR processing)
            // This is critical because Cloudonix CDR may not include the profile
            // update due to race conditions when the call ends immediately.
            OrganizationScope::bypass(fn () => $session->update([
                'amd_result' => $result,
                'amd_confidence' => $confidence,
            ]));

            $client = new CloudonixClient($settings);

            // Update Cloudonix session profile (best effort - may race with CDR)
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

            Log::info('AMD action: CONTINUE — no action taken', [
                'call_sid' => $callSid,
                'session_token' => $sessionToken,
            ]);

            return response()->json([
                'status' => 'ok',
                'action' => 'continue',
                'profile_updated' => $profileUpdated,
            ]);
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
     * Validate the AMD worker's Bearer token; returns a 401 response on failure.
     */
    private function rejectUnauthorized(Request $request): ?JsonResponse
    {
        $authHeader = (string) $request->header('Authorization', '');
        $expectedToken = (string) config('services.amd_worker.api_token', '');

        if ($expectedToken === '') {
            Log::error('AMD worker: AMD_WORKER_API_TOKEN is not configured');

            return response()->json(['status' => 'error', 'message' => 'AMD worker authentication is not configured'], 503);
        }

        if (hash_equals('Bearer '.$expectedToken, $authHeader)) {
            return null;
        }

        Log::warning('AMD worker: Unauthorized request', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'auth_present' => $authHeader !== '',
        ]);

        return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
}
