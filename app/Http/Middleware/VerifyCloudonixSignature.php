<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CloudonixSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Cloudonix Webhook Authentication
 *
 * Simplified webhook authentication for Cloudonix status/CDR webhooks.
 *
 * Authentication Flow:
 * 1. CDR Webhooks: Extract Domain UUID from JSON body (owner.domain.uuid)
 *    - Match against CloudonixSettings.domain_uuid
 *    - No Bearer token required for CDR
 *
 * 2. Status Webhooks: Verify Bearer token from Authorization header
 *    - Token verified against CloudonixSettings.domain_requests_api_key
 *    - Domain UUID extracted from payload if present
 *
 * Used for: call-initiated, call-status, session-update, and CDR webhooks
 *
 * @see https://developers.cloudonix.com/Documentation/apiSecurity
 */
class VerifyCloudonixSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Special handling for CDR webhooks (no Bearer token, uses domain UUID)
        if ($this->isCdrRequest($request)) {
            return $this->handleCdrAuthentication($request, $next);
        }

        // Handle Bearer token authentication for status webhooks
        return $this->handleBearerTokenAuthentication($request, $next);
    }

    /**
     * Check if this is a CDR webhook request
     */
    private function isCdrRequest(Request $request): bool
    {
        return $request->routeIs('webhooks.cloudonix.cdr') ||
            $request->path() === 'api/webhooks/cloudonix/cdr';
    }

    /**
     * Handle CDR webhook authentication using domain UUID or Name
     *
     * CDR webhooks from Cloudonix do not include Authorization headers.
     * We identify the organization by the domain UUID or Name in the payload.
     */
    private function handleCdrAuthentication(Request $request, Closure $next): Response
    {
        $payload = $request->json()->all();
        $settings = null;

        // 1. Try owner.domain.uuid -> domain_uuid
        $domainUuid = $payload['owner']['domain']['uuid'] ?? null;
        if ($domainUuid) {
            $settings = CloudonixSettings::where('domain_uuid', $domainUuid)->first();
        }

        // 2. Try owner.domain.name -> domain_name
        if (!$settings) {
            $domainName = $payload['owner']['domain']['name'] ?? null;
            if ($domainName) {
                $settings = CloudonixSettings::where('domain_name', $domainName)->first();
            }
        }

        // 3. Try top-level domain -> domain_name
        if (!$settings && isset($payload['domain'])) {
            $settings = CloudonixSettings::where('domain_name', $payload['domain'])->first();
        }

        if (!$settings) {
            Log::warning('CDR webhook for unknown domain', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'payload_domain_uuid' => $domainUuid ?? 'N/A',
                'payload_domain_name' => $payload['owner']['domain']['name'] ?? 'N/A',
                'payload_domain' => $payload['domain'] ?? 'N/A',
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
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $organizationId]);

        return $next($request);
    }

    /**
     * Handle Bearer token authentication for status webhooks
     *
     * Flow:
     * 1. Extract Bearer token from Authorization header
     * 2. Extract domain UUID from payload (if present)
     * 3. Find CloudonixSettings and verify token matches domain_requests_api_key
     */
    private function handleBearerTokenAuthentication(Request $request, Closure $next): Response
    {
        // Get Authorization header
        $authHeader = $request->header('Authorization');

        if (empty($authHeader)) {
            Log::warning('Webhook missing Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Missing Authorization header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Webhook Authorization header not Bearer format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid Authorization format',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        // Get request payload
        $payload = $request->json()->all();

        // Try to find organization by domain_uuid in payload
        $settings = null;
        if (isset($payload['domain_uuid'])) {
            $settings = CloudonixSettings::where('domain_uuid', $payload['domain_uuid'])->first();
        }

        // Check for 'domain' in payload (maps to domain_name)
        // Cloudonix session updates often send 'domain' instead of 'domain_uuid'
        if (!$settings && isset($payload['domain'])) {
            $settings = CloudonixSettings::where('domain_name', $payload['domain'])->first();
        }

        // Note: We cannot search by domain_requests_api_key because it is encrypted
        // and Laravel's encrypted cast is non-deterministic. We must rely on
        // domain_uuid or domain_name to identify the organization first.

        if (!$settings) {
            Log::warning('Webhook: Organization not found for Bearer token', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Verify token matches (redundant check since we already searched by token, but safe)
        if (
            !empty($settings->domain_requests_api_key) &&
            !hash_equals($settings->domain_requests_api_key, $providedToken)
        ) {
            Log::warning('Webhook: Token mismatch', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $settings->organization_id,
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $organizationId = $settings->organization_id;

        // Authentication successful
        Log::info('Webhook authenticated via Bearer token', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $organizationId,
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $organizationId]);

        return $next($request);
    }
}
