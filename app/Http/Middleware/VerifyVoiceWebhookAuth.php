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
        Log::info('Voice webhook auth middleware triggered', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
            'headers' => $request->headers->all(),
        ]);

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
        $fromNumber = $this->normalizePhoneNumber($payload['from'] ?? $payload['From'] ?? null);
        $toNumber = $this->normalizePhoneNumber($payload['to'] ?? $payload['To'] ?? null);

        // Also check X-Cx-Apikey header (Cloudonix specific)
        $cxApiKey = $request->header('X-Cx-Apikey');

        if (!$toNumber) {
            Log::warning('Voice webhook missing "to" or "To" number', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->badRequestResponse('Missing destination number');
        }

        // Identify organization by Bearer token first (most reliable)
        Log::debug('Voice Webhook Auth: Starting organization identification', [
            'bearer_token_prefix' => substr($providedToken, 0, 10) . '...',
            'cx_api_key' => $cxApiKey,
            'domain' => $domain,
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
        ]);

        $organizationId = $this->identifyOrganizationByToken($providedToken);
        $identifiedByToken = $organizationId !== null;

        // If Bearer token didn't work, try X-Cx-Apikey header
        if (!$organizationId && $cxApiKey) {
            Log::debug('Voice Webhook Auth: Trying X-Cx-Apikey for identification');
            $organizationId = $this->identifyOrganizationByToken($cxApiKey);
            $identifiedByToken = $organizationId !== null;
        }

        Log::debug('Voice Webhook Auth: Token identification result', [
            'token_used' => $identifiedByToken ? 'bearer' : ($cxApiKey && $this->identifyOrganizationByToken($cxApiKey) ? 'cx-apikey' : 'none'),
            'identified_by_token' => $identifiedByToken,
            'organization_id' => $organizationId,
        ]);

        if (!$organizationId) {
            // Fallback: identify by domain from payload
            if ($domain) {
                $organizationId = $this->identifyOrganizationByDomain($domain);
                Log::debug('Voice Webhook Auth: Domain identification result', [
                    'identified_by_domain' => $organizationId !== null,
                    'organization_id' => $organizationId,
                ]);
            }

            if (!$organizationId) {
                // Final fallback: identify by DID or extension (legacy method)
                $organizationId = $this->identifyOrganization($toNumber, $fromNumber);
                Log::debug('Voice Webhook Auth: Phone number identification result', [
                    'identified_by_phone' => $organizationId !== null,
                    'organization_id' => $organizationId,
                ]);
            }
        }

        if (!$organizationId) {
            Log::warning('Voice webhook - unable to identify organization', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'domain' => $domain,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'token_prefix' => substr($providedToken, 0, 4) . '...',
            ]);

            return $this->notFoundResponse('Unable to identify organization');
        }

        // Get organization's Cloudonix settings
        $settings = CloudonixSettings::where('organization_id', $organizationId)->first();

        if (!$settings) {
            Log::warning('Voice webhook for organization without Cloudonix settings', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'organization_id' => $organizationId,
                'domain' => $domain,
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
            ]);

            return $this->configErrorResponse('Cloudonix settings not configured');
        }

        // Verify token if we didn't identify by token (constant-time comparison)
        if (!$identifiedByToken) {
            if (empty($settings->domain_requests_api_key)) {
                Log::warning('Voice webhook for organization without domain requests API key', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'organization_id' => $organizationId,
                    'domain' => $domain,
                    'from_number' => $fromNumber,
                    'to_number' => $toNumber,
                ]);

                return $this->configErrorResponse('API key not configured');
            }

            if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
                Log::warning('Voice webhook auth token verification failed', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'organization_id' => $organizationId,
                    'domain' => $domain,
                    'from_number' => $fromNumber,
                    'to_number' => $toNumber,
                ]);

                return $this->unauthorizedResponse();
            }
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
     * Identify organization by Bearer token (domain_requests_api_key)
     */
    private function identifyOrganizationByToken(string $token): ?int
    {
        Log::info('Voice Webhook: Identifying organization by token');

        $settings = CloudonixSettings::where('domain_requests_api_key', $token)->first();

        if ($settings) {
            Log::info('Voice Webhook: Organization identified by token', [
                'settings_id' => $settings->id,
                'organization_id' => $settings->organization_id,
                'domain_name' => $settings->domain_name,
                'domain_uuid' => $settings->domain_uuid,
            ]);
            return $settings->organization_id;
        }

        Log::info('Voice Webhook: No organization found for token - checking all settings');
        // Debug: Show all CloudonixSettings for troubleshooting
        $allSettings = CloudonixSettings::all(['id', 'organization_id', 'domain_name', 'domain_uuid']);
        Log::info('Voice Webhook: All CloudonixSettings records', [
            'count' => $allSettings->count(),
            'settings' => $allSettings->map(function($s) {
                return [
                    'id' => $s->id,
                    'org_id' => $s->organization_id,
                    'domain' => $s->domain_name,
                    'uuid' => $s->domain_uuid,
                ];
            })->toArray(),
        ]);

        return null;
    }

    /**
     * Identify organization by Cloudonix domain name or UUID
     */
    private function identifyOrganizationByDomain(string $domain): ?int
    {
        Log::info('Voice Webhook: Identifying organization by domain', [
            'domain' => $domain,
        ]);

        // Try to find by domain name first
        $settings = CloudonixSettings::where('domain_name', $domain)->first();

        if (!$settings) {
            // Try to find by domain UUID
            $settings = CloudonixSettings::where('domain_uuid', $domain)->first();
        }

        if ($settings) {
            Log::info('Voice Webhook: Organization identified by domain', [
                'settings_id' => $settings->id,
                'organization_id' => $settings->organization_id,
                'matched_by' => $settings->domain_name === $domain ? 'name' : 'uuid',
            ]);
            return $settings->organization_id;
        }

        Log::info('Voice Webhook: No organization found for domain');
        return null;
    }

    /**
     * Identify organization by DID (external call) or extension (internal call)
     */
    private function identifyOrganization(?string $toNumber, ?string $fromNumber): ?int
    {
        Log::debug('Voice Webhook: Identifying organization', [
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
        ]);

        // Try to identify organization by DID (external call scenario)
        $didNumber = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->where('phone_number', $toNumber)
            ->where('status', 'active')
            ->first();

        if ($didNumber) {
            Log::debug('Voice Webhook: Organization identified by DID', [
                'did_number' => $toNumber,
                'organization_id' => $didNumber->organization_id,
            ]);
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
                Log::debug('Voice Webhook: Organization identified by extension', [
                    'extension_number' => $fromNumber,
                    'organization_id' => $fromExtension->organization_id,
                ]);
                return $fromExtension->organization_id;
            }

            // For IVR callbacks, also check if To number might be an extension
            // This handles cases where the call flow changes the number context
            $toExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('extension_number', $toNumber)
                ->whereIn('type', ['user', 'ai_assistant'])
                ->where('status', 'active')
                ->first();

            if ($toExtension) {
                Log::debug('Voice Webhook: Organization identified by To extension (IVR callback)', [
                    'extension_number' => $toNumber,
                    'organization_id' => $toExtension->organization_id,
                ]);
                return $toExtension->organization_id;
            }
        }

        // Try reverse lookup - check if To number is an extension
        if ($toNumber) {
            $toExtension = Extension::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                ->where('extension_number', $toNumber)
                ->whereIn('type', ['user', 'ai_assistant'])
                ->where('status', 'active')
                ->first();

            if ($toExtension) {
                Log::debug('Voice Webhook: Organization identified by To extension (fallback)', [
                    'extension_number' => $toNumber,
                    'organization_id' => $toExtension->organization_id,
                ]);
                return $toExtension->organization_id;
            }
        }

        Log::warning('Voice Webhook: Unable to identify organization from numbers', [
            'to_number' => $toNumber,
            'from_number' => $fromNumber,
        ]);

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
