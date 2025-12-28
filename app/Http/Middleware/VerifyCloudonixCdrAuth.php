<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to verify Cloudonix CDR webhook Bearer token authentication.
 *
 * Cloudonix sends CDR webhooks with a Bearer token in the Authorization header.
 * This middleware validates that token against the organization's domain_requests_api_key
 * from CloudonixSettings (same key used for voice and session update webhooks).
 */
class VerifyCloudonixCdrAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get Authorization header
        $authHeader = $request->header('Authorization');

        if (empty($authHeader)) {
            Log::warning('CDR webhook missing Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Missing Authorization header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('CDR webhook Authorization header not Bearer format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'auth_header_prefix' => substr($authHeader, 0, 10),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid Authorization format',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        // Get the "to" number from request payload to find the organization
        $payload = $request->json()->all();
        $toNumber = $this->normalizePhoneNumber($payload['to'] ?? null);

        if (!$toNumber) {
            Log::warning('CDR webhook missing "to" number in payload', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Bad Request - Missing "to" number',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find organization by DID number (bypass organization scope for webhook context)
        $didNumber = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if (!$didNumber) {
            Log::warning('CDR webhook for unknown DID', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'to_number' => $toNumber,
            ]);

            return response()->json([
                'error' => 'Not Found - DID not configured',
            ], Response::HTTP_NOT_FOUND);
        }

        // Get organization's Cloudonix settings
        $settings = CloudonixSettings::where('organization_id', $didNumber->organization_id)->first();

        if (!$settings || empty($settings->domain_requests_api_key)) {
            Log::warning('CDR webhook for organization without domain requests API key configured', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $didNumber->organization_id,
                'to_number' => $toNumber,
            ]);

            return response()->json([
                'error' => 'Configuration Error - Domain requests API key not configured for this organization',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
            Log::warning('CDR webhook auth token verification failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $didNumber->organization_id,
                'to_number' => $toNumber,
                'provided_token_length' => strlen($providedToken),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid auth token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Token is valid - log success and proceed
        Log::info('CDR webhook authenticated successfully', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $didNumber->organization_id,
            'to_number' => $toNumber,
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $didNumber->organization_id]);

        return $next($request);
    }

    /**
     * Normalize phone number to E.164 format.
     */
    private function normalizePhoneNumber(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        // Remove common prefixes and formatting
        $number = preg_replace('/[^0-9+]/', '', $number);

        // Ensure + prefix for E.164
        if (!str_starts_with($number, '+')) {
            $number = '+' . $number;
        }

        return $number;
    }
}
