<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CloudonixSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to verify Cloudonix webhook signatures.
 *
 * This middleware validates that incoming webhooks from Cloudonix are authentic
 * by verifying HMAC-SHA256 signatures. This prevents unauthorized webhook calls
 * and ensures the integrity of webhook data.
 *
 * Special handling for CDR webhooks:
 * - CDR webhooks from Cloudonix do not include Authorization headers or signatures
 * - Instead, organization is identified by owner.domain.uuid in the payload
 * - This is matched against CloudonixSettings.domain_uuid
 *
 * Used for: call-initiated, call-status, session-update, and CDR webhooks
 *
 * @see https://developers.cloudonix.com/Documentation/apiSecurity
 */
class VerifyCloudonixSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Special handling for CDR webhooks (no signature, uses domain UUID)
        if ($this->isCdrRequest($request)) {
            return $this->handleCdrAuthentication($request, $next);
        }
        // Skip verification if disabled (e.g., development/testing)
        if (! config('cloudonix.verify_signature', true)) {
            Log::warning('Webhook signature verification is DISABLED', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        // Get webhook secret from configuration
        $secret = config('cloudonix.webhook_secret');

        if (empty($secret)) {
            Log::critical('Webhook secret not configured but verification is enabled', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Webhook configuration error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Get signature from request header
        $headerName = config('cloudonix.signature_header', 'X-Cloudonix-Signature');
        $providedSignature = $request->header($headerName);

        if (empty($providedSignature)) {
            Log::warning('Webhook signature missing', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'headers' => $request->headers->all(),
            ]);

            return response()->json([
                'error' => 'Unauthorized - Missing signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Get raw request body for signature verification
        $payload = $request->getContent();

        // Compute expected signature using HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Timing-safe comparison to prevent timing attacks
        if (! hash_equals($expectedSignature, $providedSignature)) {
            Log::warning('Webhook signature verification failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'provided_signature' => $providedSignature,
                'expected_signature_prefix' => substr($expectedSignature, 0, 8).'...',
            ]);

            return response()->json([
                'error' => 'Unauthorized - Invalid signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Signature is valid - log success and proceed
        Log::info('Webhook signature verified successfully', [
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

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
     * CDR webhooks from Cloudonix do not include Authorization headers or signatures.
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
}
