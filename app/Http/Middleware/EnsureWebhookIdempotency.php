<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures webhook idempotency by tracking processed webhook IDs in Redis.
 */
class EnsureWebhookIdempotency
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $this->getIdempotencyKey($request);

        if (!$idempotencyKey) {
            Log::warning('Webhook received without idempotency key', [
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
            ]);

            // Allow webhook without key but log warning
            return $next($request);
        }

        $cacheKey = "idem:webhook:{$idempotencyKey}";
        $ttl = config('cloudonix.webhooks.idempotency_ttl', 86400);

        // Check if webhook was already processed
        if (Cache::has($cacheKey)) {
            Log::info('Duplicate webhook detected (idempotent)', [
                'idempotency_key' => $idempotencyKey,
                'url' => $request->fullUrl(),
            ]);

            // Return cached response or 200 OK
            $cachedResponse = Cache::get($cacheKey);

            if (is_array($cachedResponse) && isset($cachedResponse['status'], $cachedResponse['content'])) {
                return response($cachedResponse['content'], $cachedResponse['status'])
                    ->header('Content-Type', 'application/xml');
            }

            return response('', 200);
        }

        // Process the request
        $response = $next($request);

        // Cache the idempotency key with response
        try {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'content' => $response->getContent(),
                'processed_at' => now()->toIso8601String(),
            ], $ttl);

            Log::debug('Webhook idempotency key cached', [
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cache webhook idempotency key', [
                'idempotency_key' => $idempotencyKey,
                'exception' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Generate idempotency key from request.
     */
    private function getIdempotencyKey(Request $request): ?string
    {
        // Try to get explicit idempotency key from header
        $headerKey = $request->header('X-Idempotency-Key');

        if ($headerKey) {
            return $headerKey;
        }

        // Try to get from Cloudonix webhook payload
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $eventType = $request->input('CallStatus') ?? $request->input('event_type');

        if ($callId && $eventType) {
            return hash('sha256', $callId . ':' . $eventType);
        }

        // Generate from full payload as last resort
        $payload = $request->all();

        if (!empty($payload)) {
            return hash('sha256', json_encode($payload));
        }

        return null;
    }
}
