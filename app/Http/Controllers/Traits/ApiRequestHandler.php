<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * API Request Handler Trait
 *
 * Provides common functionality for API controllers including:
 * - Unique request ID generation
 * - User authentication handling
 * - Consistent request logging
 * - Standardized error responses
 *
 * @package App\Http\Controllers\Traits
 */
trait ApiRequestHandler
{
    /**
     * Get or generate a unique request ID for logging.
     */
    protected function getRequestId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Get call ID from request if present (for call tracing).
     * 
     * Checks multiple sources in order of preference:
     * 1. X-Call-ID header (standard for call-related operations)
     * 2. call_id query/body parameter (webhook/API parameter)
     * 3. CallSid parameter (Cloudonix webhook format)
     * 
     * @return string|null Call ID if present, null otherwise
     */
    protected function getCallId(): ?string
    {
        $request = request();
        
        // Check header first (most reliable for internal operations)
        if ($callId = $request->header('X-Call-ID')) {
            return $callId;
        }
        
        // Check request parameters (webhook/API calls)
        if ($callId = $request->input('call_id')) {
            return $callId;
        }
        
        // Check Cloudonix webhook format
        if ($callId = $request->input('CallSid')) {
            return $callId;
        }
        
        return null;
    }

    /**
     * Get base logging context with optional call ID.
     * 
     * @return array<string, mixed>
     */
    protected function getLoggingContext(): array
    {
        $context = [
            'request_id' => $this->getRequestId(),
        ];
        
        // Add call_id if present (for call tracing)
        if ($callId = $this->getCallId()) {
            $context['call_id'] = $callId;
        }
        
        return $context;
    }

    /**
     * Get authenticated user and abort if not authenticated.
     *
     * @return object User model (never null - aborts with 401 if not authenticated)
     */
    protected function getAuthenticatedUser(): object
    {
        $user = request()->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        return $user;
    }

    /**
     * Log request and handle with consistent response structure.
     *
     * @param string $action Description of the action being performed
     * @param array $data Response data
     * @param array $extra Additional metadata to log
     * @return JsonResponse
     */
    protected function logAndRespond(
        string $action,
        array $data = [],
        array $extra = []
    ): JsonResponse {
        $user = $this->getAuthenticatedUser();
        $context = $this->getLoggingContext();

        try {
            Log::info($action, array_merge($context, [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ip_address' => request()->ip(),
            ], $extra));

            return response()->json(array_merge(['data' => $data], $extra));
        } catch (\Exception $e) {
            Log::error($action . ' failed', array_merge($context, [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));

            return response()->json(['error' => 'Operation failed'], 500);
        }
    }

    /**
     * Log error and return standardized error response.
     *
     * @param array $details Additional details to include in response
     * @param string $message Error message
     * @param int $status HTTP status code
     * @param string $errorCode Application error code
     * @param string $requestId Request ID for correlation
     * @return JsonResponse
     */
    protected function logAndRespondError(
        array $details,
        string $message,
        int $status,
        string $errorCode,
        string $requestId
    ): JsonResponse {
        $context = $this->getLoggingContext();
        
        Log::warning('Authentication error', array_merge($context, [
            'error_code' => $errorCode,
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'ip_address' => request()->ip(),
        ]));

        return response()->json([
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'details' => $details,
                'request_id' => $requestId,
            ],
        ], $status);
    }
}