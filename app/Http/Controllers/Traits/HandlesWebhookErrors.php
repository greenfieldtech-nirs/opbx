<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use App\Exceptions\Webhook\WebhookBusinessLogicException;
use App\Exceptions\Webhook\WebhookException;
use App\Exceptions\Webhook\WebhookTransientException;
use App\Exceptions\Webhook\WebhookValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Predis\Connection\ConnectionException as RedisConnectionException;

/**
 * Trait for consistent webhook error handling across controllers.
 *
 * Provides methods to convert exceptions into appropriate HTTP responses
 * with correct status codes and retry hints for external webhook consumers.
 */
trait HandlesWebhookErrors
{
    /**
     * Handle an exception and return appropriate webhook response.
     *
     * @param \Exception $exception The exception to handle
     * @param string|null $callId Optional call ID for logging context
     * @return JsonResponse|Response
     */
    protected function handleWebhookException(\Exception $exception, ?string $callId = null): JsonResponse|Response
    {
        // Webhook-specific exceptions (already have proper status codes)
        if ($exception instanceof WebhookException) {
            return $this->respondWithWebhookException($exception, $callId);
        }

        // Laravel validation errors -> 400 Bad Request
        if ($exception instanceof ValidationException) {
            Log::warning('Webhook validation failed', [
                'call_id' => $callId,
                'errors' => $exception->errors(),
            ]);

            return response()->json([
                'error' => 'Validation failed',
                'details' => $exception->errors(),
                'retryable' => false,
            ], 400);
        }

        // Model not found -> 422 Unprocessable Entity
        if ($exception instanceof ModelNotFoundException) {
            Log::error('Webhook resource not found', [
                'call_id' => $callId,
                'model' => $exception->getModel(),
                'ids' => $exception->getIds(),
            ]);

            return response()->json([
                'error' => 'Resource not found',
                'type' => 'ModelNotFoundException',
                'retryable' => false,
            ], 422);
        }

        // Redis connection errors -> 503 Service Unavailable (retry)
        if ($exception instanceof RedisConnectionException || $exception instanceof \RedisException) {
            Log::error('Redis unavailable during webhook processing', [
                'call_id' => $callId,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'Service temporarily unavailable',
                'type' => 'RedisConnectionException',
                'retryable' => true,
            ], 503)
            ->header('Retry-After', '30');
        }

        // Database query errors -> check if transient or permanent
        if ($exception instanceof QueryException) {
            return $this->handleQueryException($exception, $callId);
        }

        // Generic exceptions -> 500 Internal Server Error (retry)
        Log::error('Webhook processing failed', [
            'call_id' => $callId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        return response()->json([
            'error' => 'Internal server error',
            'type' => class_basename($exception),
            'retryable' => true,
        ], 500);
    }

    /**
     * Respond with a webhook-specific exception.
     */
    private function respondWithWebhookException(WebhookException $exception, ?string $callId): JsonResponse
    {
        $statusCode = $exception->getHttpStatus();
        $logLevel = $statusCode >= 500 ? 'error' : 'warning';

        Log::$logLevel('Webhook exception', [
            'call_id' => $callId,
            'exception' => class_basename($exception),
            'message' => $exception->getMessage(),
            'status_code' => $statusCode,
            'retryable' => $exception->shouldRetry(),
        ]);

        $response = response()->json($exception->toArray(), $statusCode);

        if ($retryAfter = $exception->getRetryAfter()) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * Handle database query exceptions.
     */
    private function handleQueryException(QueryException $exception, ?string $callId): JsonResponse
    {
        $errorCode = $exception->errorInfo[1] ?? null;

        // Transient database errors (connection issues, deadlocks)
        $transientErrors = [
            1040, // Too many connections
            1205, // Lock wait timeout
            1213, // Deadlock
            2002, // Connection refused
            2003, // Can't connect
            2006, // MySQL server has gone away
            2013, // Lost connection during query
        ];

        if (in_array($errorCode, $transientErrors, true)) {
            Log::error('Transient database error during webhook', [
                'call_id' => $callId,
                'error_code' => $errorCode,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'Service temporarily unavailable',
                'type' => 'DatabaseConnectionError',
                'retryable' => true,
            ], 503)
            ->header('Retry-After', '30');
        }

        // Permanent database errors (constraint violations, syntax errors)
        Log::error('Database error during webhook', [
            'call_id' => $callId,
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'sql' => $exception->getSql(),
        ]);

        return response()->json([
            'error' => 'Database error occurred',
            'type' => 'QueryException',
            'retryable' => false,
        ], 500);
    }

    /**
     * Return CXML error response for voice routing errors.
     *
     * @param string $message Error message to speak to caller
     * @param int $statusCode HTTP status code
     * @return Response
     */
    protected function cxmlErrorResponse(string $message, int $statusCode = 200): Response
    {
        $cxml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<Response>\n" .
                "  <Say language=\"en-US\">{$message}</Say>\n" .
                "  <Hangup/>\n" .
                "</Response>";

        return response($cxml, $statusCode)
            ->header('Content-Type', 'application/xml');
    }
}
