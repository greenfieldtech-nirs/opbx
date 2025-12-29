<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Webhook\WebhookValidationException;
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
 * Security features:
 * - HMAC-SHA256 signature verification (timing-safe comparison)
 * - Timestamp validation to prevent replay attacks (5-minute window)
 * - Organization ID extraction and injection into request
 * - Optional IP allowlisting
 * - Signature version support for future algorithm changes
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
     * Maximum age of webhook timestamp in seconds (5 minutes).
     * Prevents replay attacks by rejecting old webhooks.
     */
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * Current signature version.
     * Can be incremented when changing signature algorithm.
     */
    private const SIGNATURE_VERSION = '1';
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Special handling for CDR webhooks (no signature, uses domain UUID)
            if ($this->isCdrRequest($request)) {
                return $this->handleCdrAuthentication($request, $next);
            }

            // Check IP allowlist if configured
            $this->validateIpAddress($request);

            // Skip verification if disabled (e.g., development/testing)
            if (! config('cloudonix.verify_signature', true)) {
                Log::warning('Webhook signature verification is DISABLED', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);

                return $next($request);
            }
        } catch (WebhookValidationException $e) {
            // Convert validation exception to HTTP response
            return response()->json($e->toArray(), $e->getHttpStatus());
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

        try {
            // Validate timestamp to prevent replay attacks
            $this->validateTimestamp($request);

            // Extract and inject organization ID for non-CDR webhooks
            $this->extractOrganizationId($request);
        } catch (WebhookValidationException $e) {
            // Convert validation exception to HTTP response
            return response()->json($e->toArray(), $e->getHttpStatus());
        }

        // Signature is valid - log success and proceed
        Log::info('Webhook signature verified successfully', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $request->input('_organization_id'),
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

    /**
     * Validate request IP address against allowlist (if configured).
     *
     * @throws WebhookValidationException
     */
    private function validateIpAddress(Request $request): void
    {
        $allowedIps = config('cloudonix.webhook_allowed_ips', []);

        if (empty($allowedIps)) {
            return; // No IP restriction
        }

        $requestIp = $request->ip();

        if (! in_array($requestIp, $allowedIps, true)) {
            Log::warning('Webhook from unauthorized IP address', [
                'ip' => $requestIp,
                'path' => $request->path(),
                'allowed_ips' => $allowedIps,
            ]);

            throw new WebhookValidationException(
                'Unauthorized IP address',
                ['ip' => $requestIp]
            );
        }
    }

    /**
     * Validate webhook timestamp to prevent replay attacks.
     *
     * @throws WebhookValidationException
     */
    private function validateTimestamp(Request $request): void
    {
        // Get timestamp header
        $timestampHeader = config('cloudonix.timestamp_header', 'X-Cloudonix-Timestamp');
        $timestamp = $request->header($timestampHeader);

        // Timestamp validation is optional (disabled if not configured)
        if (empty($timestamp)) {
            // If timestamp header not configured or not provided, skip validation
            if (config('cloudonix.require_timestamp', false)) {
                Log::warning('Webhook timestamp missing but required', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);

                throw new WebhookValidationException(
                    'Missing timestamp header',
                    ['header' => $timestampHeader]
                );
            }

            return; // Timestamp not required
        }

        // Validate timestamp format (Unix timestamp)
        if (! is_numeric($timestamp)) {
            Log::warning('Webhook timestamp invalid format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'timestamp' => $timestamp,
            ]);

            throw new WebhookValidationException(
                'Invalid timestamp format',
                ['timestamp' => $timestamp]
            );
        }

        $timestampInt = (int) $timestamp;
        $now = time();
        $age = abs($now - $timestampInt);

        // Check if timestamp is within tolerance window
        if ($age > self::TIMESTAMP_TOLERANCE) {
            Log::warning('Webhook timestamp outside tolerance window (possible replay attack)', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'timestamp' => $timestampInt,
                'age_seconds' => $age,
                'tolerance' => self::TIMESTAMP_TOLERANCE,
            ]);

            throw new WebhookValidationException(
                'Timestamp outside tolerance window',
                [
                    'timestamp' => $timestampInt,
                    'age' => $age,
                    'tolerance' => self::TIMESTAMP_TOLERANCE,
                ]
            );
        }
    }

    /**
     * Extract organization ID from webhook payload and inject into request.
     *
     * For non-CDR webhooks, try to extract organization information from:
     * 1. domain_uuid in payload (match against CloudonixSettings)
     * 2. organization_id field in payload
     */
    private function extractOrganizationId(Request $request): void
    {
        $payload = $request->json()->all();

        // Try to find organization by domain_uuid if present
        if (isset($payload['domain_uuid'])) {
            $settings = CloudonixSettings::where('domain_uuid', $payload['domain_uuid'])->first();

            if ($settings) {
                $request->merge(['_organization_id' => $settings->organization_id]);

                Log::debug('Organization extracted from domain_uuid', [
                    'organization_id' => $settings->organization_id,
                    'domain_uuid' => $payload['domain_uuid'],
                ]);

                return;
            }
        }

        // Try direct organization_id field
        if (isset($payload['organization_id'])) {
            $request->merge(['_organization_id' => (int) $payload['organization_id']]);

            Log::debug('Organization extracted from payload', [
                'organization_id' => $payload['organization_id'],
            ]);

            return;
        }

        // Organization ID not found - will be handled by rate limiting middleware
        Log::debug('No organization ID found in webhook payload', [
            'path' => $request->path(),
            'payload_keys' => array_keys($payload),
        ]);
    }
}
