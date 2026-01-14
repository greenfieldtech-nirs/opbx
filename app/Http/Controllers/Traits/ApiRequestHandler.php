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
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        try {
            Log::info($action, array_merge([
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ip_address' => request()->ip(),
            ], $extra));

            return response()->json(array_merge(['data' => $data], $extra));
        } catch (\Exception $e) {
            Log::error($action . ' failed', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
        Log::warning('Authentication error', [
            'request_id' => $requestId,
            'error_code' => $errorCode,
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'ip_address' => request()->ip(),
        ]);

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