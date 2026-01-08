<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AiAgentRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::AI_ASSISTANT;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var Extension $extension */
        $extension = $destination['extension'] ?? null;

        if (!$extension) {
            return response(CxmlBuilder::unavailable('AI Agent not found'), 200, ['Content-Type' => 'text/xml']);
        }

        // Extract service provider configuration from extension
        // Support both new columns and legacy configuration JSON
        $config = $extension->configuration ?? [];

        // Check new columns first (preferred format)
        if ($extension->service_url) {
            $serviceUrl = $extension->service_url;
            $serviceToken = $extension->service_token;
            $serviceParams = $extension->service_params ?? [];

            if (!$serviceUrl) {
                return response(CxmlBuilder::unavailable('AI Agent service URL not configured'), 200, ['Content-Type' => 'text/xml']);
            }

            return response(
                CxmlBuilder::dialService($serviceUrl, $serviceToken, $serviceParams),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Fall back to legacy configuration format
        $provider = $config['provider'] ?? null;
        $phoneNumber = $config['phone_number'] ?? null;

        if (!$provider || !$phoneNumber) {
            return response(CxmlBuilder::unavailable('AI Agent provider or phone number not configured'), 200, ['Content-Type' => 'text/xml']);
        }

        // Route to AI Agent Service Provider using <Service> noun with provider and phone number
        return response(
            CxmlBuilder::dialServiceProvider($provider, $phoneNumber),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
