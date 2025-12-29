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
 * 2. Identifies the organization from DID or extension in the request
 * 3. Verifies the token against the organization's domain_requests_api_key
 * 4. Attaches organization_id to the request for controller use
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

        // Get request payload
        $payload = $request->json()->all();
        $fromNumber = $this->normalizePhoneNumber($payload['from'] ?? $payload['From'] ?? null);
        $toNumber = $this->normalizePhoneNumber($payload['to'] ?? $payload['To'] ?? null);

        if (!$toNumber) {
            Log::warning('Voice webhook missing "to" or "To" number', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->badRequestResponse('Missing destination number');
        }

        // Identify organization by DID or extension
        $organizationId = $this->identifyOrganization($toNumber, $fromNumber);

        if (!$organizationId) {
            Log::warning('Voice webhook - unable to identify organization', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return $this->notFoundResponse('Unable to identify organization');
        }

        // Get organization's Cloudonix settings
        $settings = CloudonixSettings::where('organization_id', $organizationId)->first();

        if (!$settings || empty($settings->domain_requests_api_key)) {
            Log::warning('Voice webhook for organization without domain requests API key', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $organizationId,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return $this->configErrorResponse('API key not configured');
        }

        // Verify token (constant-time comparison)
        if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
            Log::warning('Voice webhook auth token verification failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $organizationId,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return $this->unauthorizedResponse();
        }

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
     * Identify organization by DID (external call) or extension (internal call)
     */
    private function identifyOrganization(?string $toNumber, ?string $fromNumber): ?int
    {
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

    /**
     * Return not found response in CXML format
     */
    private function notFoundResponse(string $message): Response
    {
        $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<Response>' . "\n" .
            "  <Say language=\"en-US\">Not found. {$message}.</Say>" . "\n" .
            '  <Hangup/>' . "\n" .
            '</Response>';

        return response($cxml, 404)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * Return configuration error response in CXML format
     */
    private function configErrorResponse(string $message): Response
    {
        $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<Response>' . "\n" .
            "  <Say language=\"en-US\">Configuration error. {$message}.</Say>" . "\n" .
            '  <Hangup/>' . "\n" .
            '</Response>';

        return response($cxml, 500)
            ->header('Content-Type', 'application/xml');
    }
}
