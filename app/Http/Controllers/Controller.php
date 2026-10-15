<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Client-supplied page size, clamped to [1, $max].
     */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }

    /**
     * Standard list response: `{data, meta}` with the pagination meta.
     *
     * @param  mixed  $data  Already-shaped rows (resolved resource collection, array, ...)
     * @param  bool  $withRange  Also emit `from` / `to` in meta
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, mixed $data, bool $withRange = false): JsonResponse
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        if ($withRange) {
            $meta['from'] = $paginator->firstItem();
            $meta['to'] = $paginator->lastItem();
        }

        return response()->json(['data' => $data, 'meta' => $meta]);
    }

    /**
     * Standard JSON error: `{error: {code, message, details}}`.
     *
     * @param  string  $message  Human-readable error message
     * @param  int  $status  HTTP status code
     * @param  string|null  $code  Application error code (derived from status when null)
     * @param  array<string, mixed>  $details  Additional error details
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
