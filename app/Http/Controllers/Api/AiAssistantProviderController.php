<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiAssistant\ProviderRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Controller for AI Assistant provider information.
 *
 * Provides metadata about available AI Assistant providers for the frontend
 * to render dynamic configuration forms.
 */
class AiAssistantProviderController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry
    ) {}

    /**
     * Get all available AI Assistant providers.
     *
     * Returns provider metadata including protocol, configuration fields,
     * and validation rules for dynamic form rendering.
     */
    public function index(): JsonResponse
    {
        $providers = $this->providerRegistry->getAllProviders();

        // Group providers by protocol
        $grouped = [
            'sip' => [],
            'websocket' => [],
            'dummy' => [],
        ];

        $providersArray = [];
        foreach ($providers as $provider) {
            $providerArray = $provider->toArray();
            $providersArray[] = $providerArray;
            $grouped[$provider->protocol][] = $providerArray;
        }

        return response()->json([
            'data' => [
                'providers' => $providersArray,
                'grouped' => $grouped,
                'protocols' => ['sip', 'websocket', 'dummy'],
            ],
        ]);
    }

    /**
     * Get a specific provider by key.
     */
    public function show(string $providerKey): JsonResponse
    {
        $provider = $this->providerRegistry->getProvider($providerKey);

        if (! $provider) {
            return response()->json([
                'message' => 'Provider not found',
            ], 404);
        }

        return response()->json([
            'data' => $provider->toArray(),
        ]);
    }

    /**
     * Get providers filtered by protocol.
     */
    public function byProtocol(string $protocol): JsonResponse
    {
        if (! in_array($protocol, ['sip', 'websocket', 'dummy'])) {
            return response()->json([
                'message' => 'Invalid protocol. Must be "sip", "websocket", or "dummy".',
            ], 400);
        }

        $providers = $this->providerRegistry->getProvidersByProtocol($protocol);

        return response()->json([
            'data' => array_values(array_map(fn ($p) => $p->toArray(), $providers)),
        ]);
    }
}
