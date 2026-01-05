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
        $config = $extension->configuration ?? [];
        $serviceUrl = $config['service_url'] ?? null;
        $serviceToken = $config['service_token'] ?? null;
        $serviceParams = $config['service_params'] ?? [];

        if (!$serviceUrl) {
            return response(CxmlBuilder::unavailable('AI Agent service URL not configured'), 200, ['Content-Type' => 'text/xml']);
        }

        // Route to AI Agent Service Provider using <Service> noun
        return response(
            CxmlBuilder::dialService($serviceUrl, $serviceToken, $serviceParams),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
