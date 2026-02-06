<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;

/**
 * HandlesApiErrors Trait
 *
 * Provides standardized error response formatting across all API controllers.
 * Ensures consistent error structure for frontend consumption.
 */
trait HandlesApiErrors
{
    /**
     * Return a standardized error response.
     *
     * @param  string  $message  Human-readable error message
     * @param  int  $status  HTTP status code
     * @param  string|null  $code  Application error code
     * @param  array  $details  Additional error details
     */
    protected function errorResponse(
        string $message,
        int $status = 500,
        ?string $code = null,
        array $details = []
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code ?? $this->httpStatusToCode($status),
                'message' => $message,
                'details' => $details ?: null,
            ],
        ], $status);
    }

    /**
     * Convert HTTP status code to error code string.
     */
    private function httpStatusToCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMIT_EXCEEDED',
            500 => 'INTERNAL_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'ERROR',
        };
    }
}
