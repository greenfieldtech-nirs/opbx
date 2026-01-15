<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when attempting to delete a resource that is still in use.
 *
 * This exception provides structured information about where the resource is referenced,
 * allowing for informative error responses to API clients.
 */
class ResourceInUseException extends Exception
{
    /**
     * Create a new ResourceInUseException instance.
     *
     * @param string $resourceType The type of resource being deleted
     * @param array $references Array of references where the resource is used
     * @param string $message Optional custom message
     */
    public function __construct(
        public readonly string $resourceType,
        public readonly array $references,
        string $message = '',
    ) {
        parent::__construct($message ?: "Cannot delete {$resourceType}: resource is in use");
    }

    /**
     * Render the exception as a JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => "Cannot delete {$this->resourceType}",
            'message' => "This {$this->resourceType} is being used and cannot be deleted. Please remove all references first.",
            'references' => $this->references,
        ], 409);
    }
}