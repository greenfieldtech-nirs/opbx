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
        /** @var Extension $extension */
        $extension = $destination['extension'] ?? null;

        if (! $extension) {
            return response(CxmlBuilder::unavailable('AI Agent not found'), 200, ['Content-Type' => 'text/xml']);
        }

        // Extract service provider configuration from extension
        $config = $extension->configuration ?? [];

        // Determine protocol (default to sip for backward compatibility)
        $protocol = $config['protocol'] ?? 'sip';
        $provider = $config['provider'] ?? null;

        // Route based on protocol
        if ($protocol === 'websocket') {
            return $this->routeWebSocket($request, $extension, $config, $provider);
        } else {
            return $this->routeSip($extension, $config, $provider);
        }
    }

    /**
     * Route call to WebSocket-based AI provider.
     */
    private function routeWebSocket(Request $request, Extension $extension, array $config, ?string $provider): Response
    {
        if (! $provider) {
            Log::error('WebSocket AI Assistant missing provider', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant provider not configured'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Get provider definition
        $providerDef = $this->providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            Log::error('Invalid WebSocket provider', [
                'extension_id' => $extension->id,
                'provider' => $provider,
            ]);

            return response(
                CxmlBuilder::unavailable('Invalid AI Assistant provider configuration'),
                200,
                ['Content-Type' => 'text/xml']
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
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'provider' => $provider,
                'call_sid' => $cloudonixParams['session'] ?? null,
                'from' => $cloudonixParams['from'] ?? null,
                'to' => $cloudonixParams['to'] ?? null,
            ]);

            return response(
                CxmlBuilder::streamToWebSocket($websocketUrl),
                200,
                ['Content-Type' => 'text/xml']
            );
        } catch (\InvalidArgumentException $e) {
            Log::error('Failed to build WebSocket URL', [
                'extension_id' => $extension->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response(
                CxmlBuilder::unavailable('AI Assistant configuration error'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }
    }

    /**
     * Route call to SIP-based AI provider.
     *
     * This supports both legacy configuration and service_url column.
     */
    private function routeSip(Extension $extension, array $config, ?string $provider): Response
    {
        // Check new columns first (preferred format for generic service URLs)
        if ($extension->service_url) {
            $serviceUrl = $extension->service_url;
            $serviceToken = $extension->service_token;
            $serviceParams = $extension->service_params ?? [];

            if (! $serviceUrl) {
                return response(
                    CxmlBuilder::unavailable('AI Agent service URL not configured'),
                    200,
                    ['Content-Type' => 'text/xml']
                );
            }

            Log::info('Routing to SIP AI provider via service URL', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'service_url' => $serviceUrl,
            ]);

            return response(
                CxmlBuilder::dialService($serviceUrl, $serviceToken, $serviceParams),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Fall back to legacy configuration format (provider + phone_number)
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('SIP AI Assistant missing provider or phone number', [
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
                'has_provider' => $provider !== null,
                'has_phone_number' => $phoneNumber !== null,
            ]);

            return response(
                CxmlBuilder::unavailable('AI Agent provider or phone number not configured'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        Log::info('Routing to SIP AI provider', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        // Route to AI Agent Service Provider using <Service> noun with provider and phone number
        return response(
            CxmlBuilder::dialServiceProvider($provider, $phoneNumber),
            200,
            ['Content-Type' => 'text/xml']
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
