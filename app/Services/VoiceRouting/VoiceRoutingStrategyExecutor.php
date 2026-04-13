<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Services\VoiceRouting\Strategies\RoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Voice Routing Strategy Executor
 *
 * Executes the appropriate routing strategy for a given extension type.
 * This service encapsulates the strategy pattern execution logic.
 */
class VoiceRoutingStrategyExecutor
{
    /** @var Collection<int, RoutingStrategy> */
    private Collection $strategies;

    /**
     * Create a new strategy executor instance.
     *
     * @param  iterable<RoutingStrategy>  $strategies  Collection of routing strategies
     */
    public function __construct(
        iterable $strategies = []
    ) {
        $this->strategies = collect($strategies);
    }

    /**
     * Execute the appropriate routing strategy for the given extension type.
     *
     * @param  ExtensionType  $type  The extension type to route
     * @param  Request  $request  The incoming webhook request
     * @param  DidNumber  $did  The DID number being called
     * @param  array<string, mixed>  $destination  The resolved destination resources
     * @return Response CXML response from the routing strategy
     */
    public function executeStrategy(
        ExtensionType $type,
        Request $request,
        DidNumber $did,
        array $destination
    ): Response {
        foreach ($this->strategies as $strategy) {
            if ($strategy->canHandle($type)) {
                return $strategy->route($request, $did, $destination);
            }
        }

        Log::error('VoiceRoutingStrategyExecutor: No strategy found for extension type', [
            'type' => $type->value,
            'available_strategies' => $this->strategies->map(fn ($s) => get_class($s))->toArray(),
        ]);

        return $this->createErrorResponse('Unsupported extension type');
    }

    /**
     * Check if a strategy exists for the given extension type.
     *
     * @param  ExtensionType  $type  The extension type to check
     * @return bool True if a strategy exists, false otherwise
     */
    public function hasStrategyFor(ExtensionType $type): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->canHandle($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all registered strategies.
     *
     * @return Collection<int, RoutingStrategy>
     */
    public function getStrategies(): Collection
    {
        return $this->strategies;
    }

    /**
     * Create a CXML error response.
     *
     * @param  string  $message  The error message to speak
     * @return Response CXML response with error message and hangup
     */
    private function createErrorResponse(string $message): Response
    {
        return response(
            \App\Services\CxmlBuilder\CxmlBuilder::sayWithHangup($message, true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
