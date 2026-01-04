<?php

declare(strict_types=1);

namespace Tests\Mocks\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Services\VoiceRouting\Strategies\RoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MockRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return true;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        return response('<Response><Say>Mock Strategy Executed</Say></Response>', 200, ['Content-Type' => 'text/xml']);
    }
}
