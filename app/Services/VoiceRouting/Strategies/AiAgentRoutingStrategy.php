<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AiAgentRoutingStrategy implements RoutingStrategy
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly WebSocketUrlBuilder $urlBuilder
    ) {}

    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::AI_ASSISTANT;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        // Support both direct AiAssistant (from IVR) and Extension-based routing
        $aiAssistant = $destination['ai_assistant'] ?? null;
        $extension = $destination['extension'] ?? null;

        // If we have an Extension, extract the AI Assistant from it
        if ($extension && ! $aiAssistant) {
            // Load AI Assistant relationship if not already loaded
            if (! $extension->relationLoaded('aiAssistant')) {
                $extension->load('aiAssistant');
            }
            $aiAssistant = $extension->aiAssistant;

            if (! $aiAssistant) {
                Log::error('Extension has no AI Assistant configured', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'ai_assistant_id' => $extension->ai_assistant_id,
                ]);

                return response(
                    CxmlBuilder::unavailable('AI Assistant not configured for this extension'),
                    200,
                    ['Content-Type' => 'application/xml']
                );
            }
        }

        if (! $aiAssistant) {
            return response(CxmlBuilder::unavailable('AI Agent not found'), 200, ['Content-Type' => 'application/xml']);
        }

        // Extract configuration from AI Assistant
        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        // Route based on protocol
        if ($protocol === 'websocket') {
            return $this->routeWebSocket($request, $aiAssistant, $config, $provider, $extension);
        } elseif ($protocol === 'dummy') {
            return $this->routeDummy($aiAssistant, $extension);
        } else {
            return $this->routeSip($aiAssistant, $config, $provider, $extension);
        }
    }

    /**
     * Route call to WebSocket-based AI provider.
     */
    private function routeWebSocket(
        Request $request,
        \App\Models\AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        ?Extension $extension = null
    ): Response {
        if (! $provider) {
            Log::error('WebSocket AI Assistant missing provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'extension_id' => $extension?->id,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant provider not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Get provider definition
        $providerDef = $this->providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            Log::error('Invalid WebSocket provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
            ]);

            return response(
                CxmlBuilder::unavailable('Invalid AI Assistant provider configuration'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Extract Cloudonix webhook parameters
        $cloudonixParams = $this->extractCloudonixParameters($request);

        // Build WebSocket URL from template
        try {
            $websocketUrl = $this->urlBuilder->buildUrl(
                $providerDef->urlTemplate,
                $config,
                $cloudonixParams
            );

            Log::info('Routing to WebSocket AI provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'ai_assistant_name' => $aiAssistant->name,
                'extension_id' => $extension?->id,
                'extension_number' => $extension?->extension_number,
                'provider' => $provider,
                'call_sid' => $cloudonixParams['session'] ?? null,
                'from' => $cloudonixParams['from'] ?? null,
                'to' => $cloudonixParams['to'] ?? null,
            ]);

            return response(
                CxmlBuilder::streamToWebSocket($websocketUrl),
                200,
                ['Content-Type' => 'application/xml']
            );
        } catch (\InvalidArgumentException $e) {
            Log::error('Failed to build WebSocket URL', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant configuration error'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }
    }

    /**
     * Route call to dummy AI provider.
     *
     * Returns a simple CXML response with a test message and hangup.
     */
    private function routeDummy(
        \App\Models\AiAssistant $aiAssistant,
        ?Extension $extension = null
    ): Response {
        Log::info('Routing to Dummy AI provider', [
            'ai_assistant_id' => $aiAssistant->id,
            'ai_assistant_name' => $aiAssistant->name,
            'extension_id' => $extension?->id,
            'extension_number' => $extension?->extension_number,
        ]);

        return response(
            CxmlBuilder::dummyAiMessage(),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Route call to SIP-based AI provider.
     *
     * This supports both legacy configuration and service_url column.
     */
    private function routeSip(
        \App\Models\AiAssistant $aiAssistant,
        array $config,
        ?string $provider,
        ?Extension $extension = null
    ): Response {
        // Check extension service_url first (preferred format for generic service URLs)
        if ($extension && $extension->service_url) {
            $serviceUrl = $extension->service_url;
            $serviceToken = $extension->service_token;
            $serviceParams = $extension->service_params ?? [];

            if (! $serviceUrl) {
                return response(
                    CxmlBuilder::unavailable('AI Agent service URL not configured'),
                    200,
                    ['Content-Type' => 'application/xml']
                );
            }

            Log::info('Routing to SIP AI provider via service URL', [
                'ai_assistant_id' => $aiAssistant->id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'service_url' => $serviceUrl,
            ]);

            return response(
                CxmlBuilder::dialService($serviceUrl, $serviceToken, $serviceParams),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Fall back to legacy configuration format (provider + phone_number)
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('SIP AI Assistant missing provider or phone number', [
                'ai_assistant_id' => $aiAssistant->id,
                'extension_id' => $extension?->id,
                'extension_number' => $extension?->extension_number,
                'has_provider' => $provider !== null,
                'has_phone_number' => $phoneNumber !== null,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Agent provider or phone number not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        Log::info('Routing to SIP AI provider', [
            'ai_assistant_id' => $aiAssistant->id,
            'extension_id' => $extension?->id,
            'extension_number' => $extension?->extension_number,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        // Route to AI Agent Service Provider using <Service> noun with provider and phone number
        return response(
            CxmlBuilder::dialServiceProvider($provider, $phoneNumber),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Extract Cloudonix webhook parameters from request.
     *
     * These parameters are provided by Cloudonix in the voice routing webhook.
     *
     * @return array<string, string>
     */
    private function extractCloudonixParameters(Request $request): array
    {
        return [
            'session' => $request->input('CallSid', ''),
            'from' => $request->input('From', ''),
            'to' => $request->input('To', ''),
        ];
    }
}
