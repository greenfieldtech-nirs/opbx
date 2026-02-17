<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\PhoneNumberService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Voice Webhook Authentication
 *
 * Validates voice routing requests from Cloudonix using Bearer token authentication.
 *
 * Authentication Flow:
 * 1. Extract Domain from JSON body (required for organization identification)
 * 2. Find CloudonixSettings by domain_name or domain_uuid
 * 3. Verify Bearer token matches the organization's domain_requests_api_key
 *
 * Used for: voice/route, voice/ivr-input, voice/ring-group-callback
 */
class VerifyVoiceWebhookAuth
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService
    ) {}
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

        // Get request payload
        $payload = $request->json()->all();
        $domain = $payload['Domain'] ?? $payload['domain'] ?? null;
        $fromNumber = $this->phoneNumberService->stripFormatting($payload['from'] ?? $payload['From'] ?? null);
        $toNumber = $this->phoneNumberService->stripFormatting($payload['to'] ?? $payload['To'] ?? null);

        if (!$domain) {
            Log::warning('Voice webhook missing Domain in request body', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->badRequestResponse('Missing Domain in request');
        }

        if (!$toNumber) {
            Log::warning('Voice webhook missing "to" or "To" number', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->badRequestResponse('Missing destination number');
        }

        // Verify token for the organization identified by Domain
        $verificationResult = $this->verifyTokenForDomain($providedToken, $domain, $toNumber, $fromNumber);

        if (!$verificationResult['verified']) {
            Log::warning('Voice webhook authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'domain' => $domain,
                'to_number' => $toNumber,
                'reason' => $verificationResult['reason'] ?? 'unknown',
            ]);

            return $this->unauthorizedResponse();
        }

        $organizationId = $verificationResult['organization_id'];

        // Authentication successful
        Log::info('Voice webhook authenticated', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'organization_id' => $organizationId,
            'domain' => $domain,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
        ]);

        // Attach organization_id to request for controller use
        $request->merge(['_organization_id' => $organizationId]);

        return $next($request);
    }

    /**
     * Verify token against the organization identified by Domain.
     *
     * Flow:
     * 1. Find CloudonixSettings by domain_name or domain_uuid
     * 2. Verify Bearer token matches domain_requests_api_key
     *
     * @param string $providedToken The Bearer token from the request
     * @param string $domain Domain name from the request
     * @param string|null $toNumber Destination phone number for context
     * @param string|null $fromNumber Source phone number for context
     * @return array{verified: bool, organization_id?: int, reason?: string}
     */
    private function verifyTokenForDomain(
        string $providedToken,
        string $domain,
        ?string $toNumber,
        ?string $fromNumber
    ): array {
        // Find CloudonixSettings by domain
        $settings = CloudonixSettings::where('domain_name', $domain)->first();

        if (!$settings) {
            // Try to find by domain UUID
            $settings = CloudonixSettings::where('domain_uuid', $domain)->first();
        }

        if (!$settings) {
            Log::warning('Voice webhook: Organization not found for domain', [
                'domain' => $domain,
                'to_number' => $toNumber,
            ]);

            return [
                'verified' => false,
                'reason' => 'domain_not_found',
            ];
        }

        // Verify token matches domain_requests_api_key
        if (empty($settings->domain_requests_api_key)) {
            Log::warning('Voice webhook: Organization has no API key configured', [
                'organization_id' => $settings->organization_id,
                'domain' => $domain,
            ]);

            return [
                'verified' => false,
                'reason' => 'api_key_not_configured',
            ];
        }

        if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
            Log::warning('Voice webhook: Token mismatch', [
                'organization_id' => $settings->organization_id,
                'domain' => $domain,
            ]);

            return [
                'verified' => false,
                'reason' => 'invalid_token',
            ];
        }

        return [
            'verified' => true,
            'organization_id' => $settings->organization_id,
        ];
    }


    /**
     * Return unauthorized response in CXML format
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
