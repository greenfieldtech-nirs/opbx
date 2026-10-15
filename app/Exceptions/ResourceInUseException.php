<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

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
     * @param  string  $resourceType  The type of resource being deleted
     * @param  array  $references  Array of references where the resource is used
     * @param  string  $message  Optional custom message
     */
    public function __construct(
        public readonly string $resourceType,
        public readonly array $references,
        string $message = '',
    ) {
        parent::__construct($message ?: "Cannot delete {$resourceType}: resource is in use");
    }

    /**
     * Render the exception as a 409 whose message names where the resource is used.
     *
     * `references` is a list of ['type', 'id', 'name', 'detail'].
     */
    public function render(): JsonResponse
    {
        $label = str_replace(['ivr_menu', '_'], ['IVR menu', ' '], $this->resourceType);
        $lines = array_map(
            fn (array $ref): string => sprintf('%s "%s" (%s)', str_replace(['ivr_menu', '_'], ['IVR menu', ' '], $ref['type']), $ref['name'], $ref['detail']),
            array_slice($this->references, 0, 10),
        );
        $more = count($this->references) - count($lines);

        return response()->json([
            'error' => "Cannot delete {$label}",
            'message' => "This {$label} is still used by: ".implode('; ', $lines).($more > 0 ? "; and {$more} more" : '').'. Remove these references first.',
            'references' => $this->references,
        ], 409);
    }
}
