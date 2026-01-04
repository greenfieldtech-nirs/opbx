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

        $sipUri = sprintf('sip:%s@%s', $extension->extension_number, $request->input('Domain'));

        // Route to AI Agent (same as user for now, but semantically distinct)
        return response(
            CxmlBuilder::dialExtension($sipUri, 60),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
