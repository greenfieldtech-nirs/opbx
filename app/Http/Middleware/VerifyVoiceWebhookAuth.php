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
 * Verify Voice Webhook Authentication
 *
 * Validates voice routing requests from Cloudonix using Bearer token authentication.
 * This middleware:
 * 1. Extracts the Bearer token from Authorization header
 * 2. Identifies and verifies the organization using the token
 * 3. Attaches organization_id to the request for controller use
 *
 * Security Note: Token verification is ALWAYS performed when a token is provided.
 * There is no bypass - domain or phone number identification only provides
 * organization context for logging and routing, NOT authentication.
 *
 * Used for: voice/route, voice/ivr-input, voice/ring-group-callback
 */
class VerifyVoiceWebhookAuth
{
    /**
     * Handle an incoming request
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get Authorization header
        $authHeader = $request->header('Authorization');

        if (empty($authHeader)) {
            Log::warning('Voice webhook missing Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Voice webhook Authorization header not Bearer format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        // Get request payload for organization context (not authentication)
        $payload = $request->json()->all();
        $domain = $payload['Domain'] ?? $payload['domain'] ?? null;
        $fromNumber = $this->normalizePhoneNumber($payload['from'] ?? $payload['From'] ?? null);
        $toNumber = $this->normalizePhoneNumber($payload['to'] ?? $payload['To'] ?? null);

        if (!$toNumber) {
            Log::warning('Voice webhook missing "to" or "To" number', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->badRequestResponse('Missing destination number');
        }

        // SECURE: Always verify token - this is mandatory for all authentication paths
        $verificationResult = $this->verifyToken($providedToken, $toNumber, $fromNumber, $domain);

        if (!$verificationResult['verified']) {
            Log::warning('Voice webhook token verification failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'reason' => $verificationResult['reason'] ?? 'unknown',
                'to_number' => $toNumber,
            ]);

            return $this->unauthorizedResponse();
        }

        $organizationId = $verificationResult['organization_id'];
        $settings = $verificationResult['settings'];

        // Authentication successful
        Log::info('Voice webhook authenticated', [
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
     * Verify the provided token against the organization's domain_requests_api_key.
     *
     * This method ALWAYS verifies the token when provided. There is no bypass.
     * Domain and phone number identification only provides context for logging
     * and error messages, NOT authentication.
     *
     * @param string $providedToken The Bearer token from the request
     * @param string|null $toNumber Destination phone number for context
     * @param string|null $fromNumber Source phone number for context
     * @param string|null $domain Domain name for context
     * @return array{verified: bool, organization_id?: int, settings?: CloudonixSettings, reason?: string}
     */
    private function verifyToken(
        string $providedToken,
        ?string $toNumber,
        ?string $fromNumber,
        ?string $domain
    ): array {
        // Try to find organization by token first
        $settings = CloudonixSettings::where('domain_requests_api_key', $providedToken)->first();

        if ($settings) {
            return [
                'verified' => true,
                'organization_id' => $settings->organization_id,
                'settings' => $settings,
            ];
        }

        // Token not found in any organization's domain_requests_api_key
        // Try to identify organization by domain for better error message
        $orgByDomain = $this->identifyOrganizationByDomain($domain);

        Log::warning('Voice webhook auth token not found', [
            'token_provided' => true,
            'domain' => $domain,
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
            'organization_found_by_domain' => $orgByDomain !== null,
        ]);

        return [
            'verified' => false,
            'reason' => 'invalid_token',
        ];
    }

    /**
     * Identify organization by Cloudonix domain name or UUID (for context only).
     * This does NOT provide authentication - token verification is always required.
     *
     * @param string|null $domain Domain name or UUID
     * @return int|null Organization ID
     */
    private function identifyOrganizationByDomain(?string $domain): ?int
    {
        if (!$domain) {
            return null;
        }

        // Try to find by domain name first
        $settings = CloudonixSettings::where('domain_name', $domain)->first();

        if (!$settings) {
            // Try to find by domain UUID
            $settings = CloudonixSettings::where('domain_uuid', $domain)->first();
        }

        return $settings?->organization_id;
    }

    /**
     * Identify organization by DID (external call) or extension (internal call).
     * This is for CONTEXT ONLY - token verification is always required.
     *
     * @param string|null $toNumber Destination phone number
     * @param string|null $fromNumber Source phone number
     * @return int|null Organization ID
     */
    private function identifyOrganizationByPhone(?string $toNumber, ?string $fromNumber): ?int
    {
        if (!$toNumber) {
            return null;
        }

        // Try to identify organization by DID (external call scenario)
        $didNumber = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if ($didNumber) {
            return $didNumber->organization_id;
        }

        // Try to identify organization by extension (internal call scenario)
        // Check if From is an extension
        if ($fromNumber) {
            $fromExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('extension_number', $fromNumber)
                ->whereIn('type', ['user', 'ai_assistant'])
                ->where('status', 'active')
                ->first();

            if ($fromExtension) {
                return $fromExtension->organization_id;
            }
        }

        // Try reverse lookup - check if To number is an extension
        $toExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('extension_number', $toNumber)
            ->whereIn('type', ['user', 'ai_assistant'])
            ->where('status', 'active')
            ->first();

        if ($toExtension) {
            return $toExtension->organization_id;
        }

        return null;
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

    /**
     * Return unauthorized response in CXML format
     *
     * Since voice webhooks expect CXML responses, return errors
     * in CXML format instead of JSON.
     */
    private function unauthorizedResponse(): Response
    {
        $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<Response>' . "\n" .
            '  <Say language="en-US">Unauthorized. Authentication failed.</Say>' . "\n" .
            '  <Hangup/>' . "\n" .
            '</Response>';

        return response($cxml, 401)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Return bad request response in CXML format
     */
    private function badRequestResponse(string $message): Response
    {
        $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<Response>' . "\n" .
            "  <Say language=\"en-US\">Bad request. {$message}.</Say>" . "\n" .
            '  <Hangup/>' . "\n" .
            '</Response>';

        return response($cxml, 400)
            ->header('Content-Type', 'application/xml');
    }
}
