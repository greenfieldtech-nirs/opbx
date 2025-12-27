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
        $ttl = config('webhooks.idempotency.ttl', 86400);

        // Check if webhook was already processed
        if (Cache::has($cacheKey)) {
            Log::info('Duplicate webhook detected (idempotent)', [
                'idempotency_key' => $idempotencyKey,
                'url' => $request->fullUrl(),
            ]);

            // Return cached response or 200 OK
            $cachedResponse = Cache::get($cacheKey);

            if (is_array($cachedResponse)) {
                $status = $cachedResponse['status'] ?? 200;

                // If full content was cached, return it
                if (isset($cachedResponse['content'])) {
                    return response($cachedResponse['content'], $status)
                        ->header('Content-Type', 'application/xml');
                }

                // If only metadata was cached (oversized response), return simple success
                if (isset($cachedResponse['metadata_only']) && $cachedResponse['metadata_only']) {
                    Log::debug('Returning success for oversized cached response', [
                        'idempotency_key' => $idempotencyKey,
                        'original_size' => $cachedResponse['size'] ?? 'unknown',
                    ]);

                    return response('', $status);
                }
            }

            return response('', 200);
        }

        // Process the request
        $response = $next($request);

        // Cache the idempotency key with response (with size limits)
        try {
            $this->cacheResponse($cacheKey, $response, $ttl, $idempotencyKey);
        } catch (\Exception $e) {
            Log::error('Failed to cache webhook idempotency key', [
                'idempotency_key' => $idempotencyKey,
                'exception' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Cache webhook response with size limits to prevent Redis memory exhaustion.
     *
     * @param string $cacheKey Redis cache key
     * @param Response $response HTTP response to cache
     * @param int $ttl Time to live in seconds
     * @param string $idempotencyKey Idempotency key for logging
     * @return void
     */
    private function cacheResponse(string $cacheKey, Response $response, int $ttl, string $idempotencyKey): void
    {
        $content = $response->getContent();
        $contentSize = strlen($content);
        $maxSize = config('webhooks.idempotency.max_response_size', 102400);

        $status = $response->getStatusCode();

        // If response is within size limits, cache full content
        if ($contentSize <= $maxSize) {
            Cache::put($cacheKey, [
                'status' => $status,
                'content' => $content,
                'size' => $contentSize,
                'processed_at' => now()->toIso8601String(),
            ], $ttl);

            Log::debug('Webhook response cached (full content)', [
                'idempotency_key' => $idempotencyKey,
                'size' => $contentSize,
                'max_size' => $maxSize,
            ]);

            return;
        }

        // Response is too large - cache metadata only
        Cache::put($cacheKey, [
            'status' => $status,
            'metadata_only' => true,
            'size' => $contentSize,
            'processed_at' => now()->toIso8601String(),
        ], $ttl);

        Log::warning('Webhook response too large for cache, storing metadata only', [
            'idempotency_key' => $idempotencyKey,
            'size' => $contentSize,
            'max_size' => $maxSize,
            'size_exceeded_by' => $contentSize - $maxSize,
        ]);
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
