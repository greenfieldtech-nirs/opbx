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
 * Unified webhook authentication for Cloudonix status/CDR webhooks.
 *
 * Security Model:
 * 1. Domain MUST be present in payload (session-update: 'domain', CDR: 'domain' or 'owner.domain.uuid/name')
 * 2. Match domain to organization via CloudonixSettings
 * 3. If organization has domain_requests_api_key configured:
 *    - Bearer token MUST be present and match
 *    - If token missing or mismatch → 401 Unauthorized
 * 4. If organization has NO domain_requests_api_key configured:
 *    - Request processed without token validation
 *
 * This ensures backward compatibility while allowing organizations to enable
 * webhook authentication when needed.
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
        $payload = $request->json()->all();

        // Step 1: Extract domain from payload
        $domain = $this->extractDomain($payload);

        if (! $domain) {
            Log::warning('Webhook: Missing domain parameter', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Bad Request - Missing domain parameter',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Step 2: Find organization by domain
        $settings = $this->findOrganizationByDomain($domain, $payload);

        if (! $settings) {
            Log::warning('Webhook: Unknown domain', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'domain' => $domain,
            ]);

            return response()->json([
                'error' => 'Unauthorized - Unknown domain',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Step 3: Authenticate via Bearer token if organization has auth configured
        $authResult = $this->authenticateRequest($request, $settings);

        if (! $authResult['success']) {
            Log::warning('Webhook: Authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $settings->organization_id,
                'reason' => $authResult['reason'],
            ]);

            return response()->json([
                'error' => 'Unauthorized - '.$authResult['reason'],
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Authentication successful
        Log::info('Webhook authenticated', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $settings->organization_id,
            'domain' => $domain,
            'auth_method' => $authResult['method'],
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $settings->organization_id]);

        return $next($request);
    }

    /**
     * Extract domain from webhook payload.
     *
     * Tries multiple locations to support different webhook types.
     */
    private function extractDomain(array $payload): ?string
    {
        // Priority 1: Top-level 'domain' (session-update, call-status)
        if (! empty($payload['domain'])) {
            return $payload['domain'];
        }

        // Priority 2: CDR owner.domain.name
        if (! empty($payload['owner']['domain']['name'])) {
            return $payload['owner']['domain']['name'];
        }

        // Priority 3: CDR owner.domain.uuid (less common)
        if (! empty($payload['owner']['domain']['uuid'])) {
            return $payload['owner']['domain']['uuid'];
        }

        return null;
    }

    /**
     * Find organization settings by domain.
     *
     * Tries domain_name first, then domain_uuid.
     */
    private function findOrganizationByDomain(string $domain, array $payload): ?CloudonixSettings
    {
        // Try domain_name match first
        $settings = CloudonixSettings::where('domain_name', $domain)->first();

        if ($settings) {
            return $settings;
        }

        // Try domain_uuid match (if domain looks like a UUID)
        if ($this->isUuid($domain)) {
            $settings = CloudonixSettings::where('domain_uuid', $domain)->first();

            if ($settings) {
                return $settings;
            }
        }

        // Also check owner.domain.uuid if present (for CDR)
        if (! empty($payload['owner']['domain']['uuid'])) {
            $settings = CloudonixSettings::where('domain_uuid', $payload['owner']['domain']['uuid'])->first();

            if ($settings) {
                return $settings;
            }
        }

        return null;
    }

    /**
     * Check if string is a valid UUID format.
     */
    private function isUuid(string $str): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str) === 1;
    }

    /**
     * Authenticate the request based on organization's auth configuration.
     *
     * @return array{success: bool, reason: string|null, method: string|null}
     */
    private function authenticateRequest(Request $request, CloudonixSettings $settings): array
    {
        $providedToken = $this->extractBearerToken($request);

        // Case 1: Organization has webhook auth configured
        if (! empty($settings->domain_requests_api_key)) {
            if (! $providedToken) {
                return [
                    'success' => false,
                    'reason' => 'Bearer token required',
                    'method' => null,
                ];
            }

            if (! hash_equals($settings->domain_requests_api_key, $providedToken)) {
                return [
                    'success' => false,
                    'reason' => 'Invalid Bearer token',
                    'method' => null,
                ];
            }

            return [
                'success' => true,
                'reason' => null,
                'method' => 'bearer_token',
            ];
        }

        // Case 2: Organization has NO webhook auth configured
        // Allow request regardless of token presence
        return [
            'success' => true,
            'reason' => null,
            'method' => $providedToken ? 'bearer_token_ignored' : 'none',
        ];
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');

        if (empty($authHeader)) {
            return null;
        }

        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7); // Remove "Bearer " prefix
    }
}
