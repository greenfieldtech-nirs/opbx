<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Voice Webhook Authentication
 *
 * Validates that voice routing webhook requests are coming from Cloudonix
 * by verifying a bearer token.
 *
 * Configuration:
 * Set VOICE_WEBHOOK_TOKEN in .env to the shared secret token
 * that Cloudonix will send in the Authorization header.
 *
 * Cloudonix should send:
 * Authorization: Bearer {VOICE_WEBHOOK_TOKEN}
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
        $expectedToken = config('services.cloudonix.voice_webhook_token');

        // If no token is configured, allow request (development mode)
        if (empty($expectedToken)) {
            Log::warning('Voice webhook auth: No token configured, allowing request', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        // Extract bearer token from Authorization header
        $authHeader = $request->header('Authorization');
        if (empty($authHeader) || ! str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Voice webhook auth: Missing or invalid Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        $providedToken = substr($authHeader, 7); // Remove "Bearer " prefix

        // Constant-time comparison to prevent timing attacks
        if (! hash_equals($expectedToken, $providedToken)) {
            Log::warning('Voice webhook auth: Invalid token', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->unauthorizedResponse();
        }

        // Token is valid
        return $next($request);
    }

    /**
     * Return unauthorized response in CXML format
     *
     * Since voice webhooks expect CXML responses, return an error
     * in CXML format instead of JSON.
     *
     * @return Response
     */
    private function unauthorizedResponse(): Response
    {
        $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<Response>' . "\n" .
            '  <Say language="en-US">Unauthorized webhook request. Authentication failed.</Say>' . "\n" .
            '  <Hangup/>' . "\n" .
            '</Response>';

        return response($cxml, 401)
            ->header('Content-Type', 'application/xml');
    }
}
