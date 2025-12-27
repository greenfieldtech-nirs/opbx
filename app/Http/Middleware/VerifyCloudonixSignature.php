<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
}
