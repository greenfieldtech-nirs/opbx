<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QueueRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::QUEUE;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        // Placeholder for Call Center Queue logic (Phase 4+)
        return response(
            CxmlBuilder::busy('The queue system is currently under maintenance. Please try again later.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
