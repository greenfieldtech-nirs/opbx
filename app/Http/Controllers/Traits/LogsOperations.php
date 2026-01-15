<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait for standardized operation logging across controllers.
 *
 * Provides consistent log message formatting following the pattern:
 * [Resource] [operation] [completed|failed]
 */
trait LogsOperations
{
    /**
     * Log successful completion of an operation.
     *
     * @param string $resource The resource name (e.g., 'Extension', 'User')
     * @param string $operation The operation performed (e.g., 'creation', 'update', 'deletion')
     * @param array $context Additional context to include in the log
     */
    protected function logOperationCompleted(string $resource, string $operation, array $context = []): void
    {
        Log::info(ucfirst($resource) . ' ' . $operation . ' completed', $this->enrichLogContext($context));
    }

    /**
     * Log failure of an operation.
     *
     * @param string $resource The resource name (e.g., 'Extension', 'User')
     * @param string $operation The operation performed (e.g., 'creation', 'update', 'deletion')
     * @param array $context Additional context to include in the log
     * @param bool $nonBlocking Whether this is a non-blocking failure (warning instead of error)
     */
    protected function logOperationFailed(string $resource, string $operation, array $context = [], bool $nonBlocking = false): void
    {
        $level = $nonBlocking ? 'warning' : 'error';
        $suffix = $nonBlocking ? ' (non-blocking)' : '';

        Log::$level(ucfirst($resource) . ' ' . $operation . ' failed' . $suffix, $this->enrichLogContext($context));
    }

    /**
     * Log retrieval of a list of resources.
     *
     * @param string $resource The resource name (e.g., 'Extension', 'User')
     * @param array $context Additional context to include in the log
     */
    protected function logListRetrieved(string $resource, array $context = []): void
    {
        Log::info($resource . 's list retrieved', $this->enrichLogContext($context));
    }

    /**
     * Log retrieval of a single resource.
     *
     * @param string $resource The resource name (e.g., 'Extension', 'User')
     * @param array $context Additional context to include in the log
     */
    protected function logDetailsRetrieved(string $resource, array $context = []): void
    {
        Log::info($resource . ' details retrieved', $this->enrichLogContext($context));
    }

    /**
     * Enrich log context with common fields.
     *
     * Override this method in controllers to add controller-specific context enrichment.
     *
     * @param array $context The base context
     * @return array The enriched context
     */
    protected function enrichLogContext(array $context): array
    {
        // Default implementation - no enrichment
        // Controllers can override this to add request_id, user_id, etc.
        return $context;
    }
}