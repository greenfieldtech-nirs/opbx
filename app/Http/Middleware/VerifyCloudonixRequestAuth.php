<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Cloudonix Request Authentication
 *
 * Validates authentication for incoming requests from Cloudonix:
 *
 * Voice Application Requests:
 * - Require Bearer token authentication using domain_requests_api_key
 * - Organization identified by DID or extension number
 *
 * CDR Reports:
 * - No Authorization header required
 * - Organization identified by domain UUID in payload
 * - Matches against cloudonix_settings.domain_uuid
 *
 * Domain Session Updates:
 * - Require Bearer token authentication using domain_requests_api_key
 */
class VerifyCloudonixRequestAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Special handling for CDR webhooks (no Authorization header required)
        if ($this->isCdrRequest($request)) {
            return $this->handleCdrAuthentication($request, $next);
        }

        // Get Authorization header for non-CDR requests
        $authHeader = $request->header('Authorization');

        if (empty($authHeader)) {
            Log::warning('Cloudonix request missing Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Cloudonix request Authorization header not Bearer format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        // Get request payload
        $payload = $request->json()->all();
        $fromNumber = $this->normalizePhoneNumber($payload['from'] ?? $payload['From'] ?? null);
        $toNumber = $this->normalizePhoneNumber($payload['to'] ?? $payload['To'] ?? null);

        if (!$toNumber) {
            Log::warning('Cloudonix request missing "to" or "To" number', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Bad Request - Missing destination number',
            ], Response::HTTP_BAD_REQUEST);
        }

        $organizationId = null;

        // Try to identify organization by DID (external call scenario)
        $didNumber = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if ($didNumber) {
            $organizationId = $didNumber->organization_id;
        } else {
            // Try to identify organization by extension (internal call scenario)
            // Check if From is an extension
            $fromExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('extension_number', $fromNumber)
                ->whereIn('type', ['user', 'ai_assistant'])
                ->where('status', 'active')
                ->first();

            if ($fromExtension) {
                $organizationId = $fromExtension->organization_id;
            }
        }

        if (!$organizationId) {
            Log::warning('Cloudonix request - unable to identify organization', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return response()->json([
                'error' => 'Not Found - Unable to identify organization',
            ], Response::HTTP_NOT_FOUND);
        }

        // Get organization's Cloudonix settings
        $settings = CloudonixSettings::where('organization_id', $organizationId)->first();

        if (!$settings || empty($settings->domain_requests_api_key)) {
            Log::warning('Cloudonix request for organization without domain requests API key', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $organizationId,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return response()->json([
                'error' => 'Configuration Error - API key not configured',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Verify token (constant-time comparison)
        if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
            Log::warning('Cloudonix request auth token verification failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $organizationId,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return $this->unauthorizedResponse();
        }

        // Authentication successful
        Log::info('Cloudonix request authenticated', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $organizationId,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $organizationId]);

        return $next($request);
    }

    /**
     * Check if this is a CDR webhook request
     */
    private function isCdrRequest(Request $request): bool
    {
        return $request->path() === 'api/webhooks/cloudonix/cdr';
    }

    /**
     * Handle CDR webhook authentication using domain UUID
     *
     * CDR webhooks from Cloudonix do not include Authorization header.
     * Instead, we identify the organization by the domain UUID in the payload.
     * The UUID is located at owner.domain.uuid in the CDR object.
     */
    private function handleCdrAuthentication(Request $request, Closure $next): Response
    {
        $payload = $request->json()->all();

        // Extract domain UUID from CDR payload
        // CDR structure: owner.domain.uuid
        $domainUuid = $payload['owner']['domain']['uuid'] ?? null;

        // Log payload structure for debugging if UUID not found
        if (!$domainUuid) {
            Log::warning('CDR webhook missing owner.domain.uuid', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'payload_keys' => array_keys($payload),
                'has_owner' => isset($payload['owner']),
                'owner_keys' => isset($payload['owner']) ? array_keys($payload['owner']) : null,
                'has_domain' => isset($payload['owner']['domain']),
                'domain_keys' => isset($payload['owner']['domain']) ? array_keys($payload['owner']['domain']) : null,
            ]);

            return response()->json([
                'error' => 'Bad Request - Missing domain UUID in CDR payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Look up organization by domain UUID in CloudonixSettings
        $settings = CloudonixSettings::where('domain_uuid', $domainUuid)->first();

        if (!$settings) {
            Log::warning('CDR webhook for unknown domain UUID', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'domain_uuid' => $domainUuid,
            ]);

            return response()->json([
                'error' => 'Not Found - Unknown domain',
            ], Response::HTTP_NOT_FOUND);
        }

        $organizationId = $settings->organization_id;

        Log::info('CDR webhook authenticated via owner.domain.uuid', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $organizationId,
            'domain_uuid' => $domainUuid,
            'matched_setting_id' => $settings->id,
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $organizationId]);

        return $next($request);
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(): Response
    {
        return response()->json([
            'error' => 'Unauthorized - Invalid or missing authentication',
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Normalize phone number by removing formatting characters
     *
     * Note: Does NOT add + prefix automatically because we need to support
     * both extension numbers (e.g., "1005") and E.164 phone numbers (e.g., "+14155551234")
     */
    private function normalizePhoneNumber(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        // Remove non-numeric characters except +
        $number = preg_replace('/[^0-9+]/', '', $number);

        return $number;
    }
}
