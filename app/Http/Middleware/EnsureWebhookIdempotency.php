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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $this->getIdempotencyKey($request);

        if (! $idempotencyKey) {
            Log::warning('Webhook received without idempotency key', [
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
            ]);

            // Allow webhook without key but log warning
            return $next($request);
        }

        // Validate webhook timestamp to prevent replay attacks
        $timestamp = $request->input('timestamp');
        if ($timestamp) {
            $webhookAge = time() - (int) $timestamp;
            $maxAge = config('webhooks.replay_protection.max_age', 300); // 5 minutes default

            // Check if this is a CDR webhook (CDRs can arrive late due to processing delays)
            $isCdrWebhook = $request->is('api/webhooks/cloudonix/cdr');

            if ($webhookAge > $maxAge) {
                if ($isCdrWebhook) {
                    // For CDR webhooks, log but accept older records
                    Log::info('CDR webhook received with old timestamp (accepted)', [
                        'idempotency_key' => $idempotencyKey,
                        'url' => $request->fullUrl(),
                        'ip' => $request->ip(),
                        'timestamp' => $timestamp,
                        'age_seconds' => $webhookAge,
                        'max_age_seconds' => $maxAge,
                        'reason' => 'CDR processing delay - accepted',
                    ]);
                    // Continue processing - do not reject
                } else {
                    // For other webhooks, reject as potential replay attack
                    Log::warning('Webhook replay attack detected - timestamp too old', [
                        'idempotency_key' => $idempotencyKey,
                        'url' => $request->fullUrl(),
                        'ip' => $request->ip(),
                        'timestamp' => $timestamp,
                        'age_seconds' => $webhookAge,
                        'max_age_seconds' => $maxAge,
                    ]);

                    return response()->json([
                        'error' => 'Webhook Expired',
                        'message' => 'Webhook timestamp is too old. Possible replay attack.',
                    ], 400);
                }
            }

            if ($webhookAge < -60) {
                // Webhook is from the future (more than 1 minute)
                Log::warning('Webhook with future timestamp detected', [
                    'idempotency_key' => $idempotencyKey,
                    'url' => $request->fullUrl(),
                    'ip' => $request->ip(),
                    'timestamp' => $timestamp,
                    'age_seconds' => $webhookAge,
                ]);

                return response()->json([
                    'error' => 'Invalid Timestamp',
                    'message' => 'Webhook timestamp is in the future.',
                ], 400);
            }
        }

        $cacheKey = "idem:webhook:{$idempotencyKey}";
        $ttl = config('webhooks.idempotency.ttl', 86400);

        // Only successful responses are stored, so a replay is always a real success.
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse !== null) {
            Log::info('Duplicate webhook detected (idempotent)', [
                'idempotency_key' => $idempotencyKey,
                'url' => $request->fullUrl(),
            ]);

            return $this->replay($cachedResponse);
        }

        // Atomic claim: only one concurrent delivery runs the handler.
        $claimKey = $cacheKey.':lock';
        if (! Cache::add($claimKey, 1, 60)) {
            Log::info('Duplicate webhook while the first is still processing', [
                'idempotency_key' => $idempotencyKey,
                'url' => $request->fullUrl(),
            ]);

            // Concurrent duplicate. Cloudonix does not retry webhooks (owner, 2026-10-11), so 409 only
            // signals the duplicate; if the first attempt then fails, the event is not redelivered.
            return response()->json(['error' => 'Webhook is being processed'], 409);
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                try {
                    $this->cacheResponse($cacheKey, $response, $ttl, $idempotencyKey);
                } catch (\Exception $e) {
                    Log::error('Failed to cache webhook idempotency key', [
                        'idempotency_key' => $idempotencyKey,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            return $response;
        } finally {
            // Success stored the result above; failure leaves nothing, so a retry is processed.
            Cache::forget($claimKey);
        }
    }

    /**
     * Rebuild a stored successful response, preserving its original Content-Type.
     */
    private function replay(mixed $cached): Response
    {
        if (! is_array($cached)) {
            return response('', 200);
        }

        $status = $cached['status'] ?? 200;

        if (isset($cached['content'])) {
            return response($cached['content'], $status)
                ->header('Content-Type', $cached['content_type'] ?? 'application/xml');
        }

        return response('', $status);
    }

    /**
     * Cache webhook response with size limits to prevent Redis memory exhaustion.
     *
     * @param  string  $cacheKey  Redis cache key
     * @param  Response  $response  HTTP response to cache
     * @param  int  $ttl  Time to live in seconds
     * @param  string  $idempotencyKey  Idempotency key for logging
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
                'content_type' => $response->headers->get('Content-Type'),
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
        // Priority 1: Explicit idempotency key from header (if provided)
        $headerKey = $request->header('X-Idempotency-Key');

        if ($headerKey) {
            return $headerKey;
        }

        // Priority 2: Cloudonix webhook standard fields
        $callId = $request->input('CallSid') ?? $request->input('call_id');
        $eventType = $request->input('CallStatus') ?? $request->input('event_type');

        // Use route name as fallback event type - the event type is implicit in the
        // route for webhooks like call-initiated that don't carry a status field
        if ($callId && ! $eventType) {
            $eventType = $request->route()?->getName();
        }

        if ($callId && $eventType) {
            // Use call_id + event_type for a stable idempotency key.
            // Deliberately excludes timestamp so retries of the same event
            // produce the same key and are correctly deduplicated.
            $keyComponents = [
                'call_id' => $callId,
                'event_type' => $eventType,
            ];

            return hash('sha256', json_encode($keyComponents, JSON_PRESERVE_ZERO_FRACTION));
        }

        // Priority 3: Full payload as last resort
        // Use JSON_PRESERVE_ZERO_FRICTION for deterministic encoding
        // This ensures the same payload always produces the same hash
        $payload = $request->all();

        if (! empty($payload)) {
            return hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
        }

        return null;
    }
}
