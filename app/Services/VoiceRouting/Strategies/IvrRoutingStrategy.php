<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IvrRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::IVR;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        // Placeholder for IVR Menu logic (Phase 5+)
        // Would typically play a menu and wait for Digits
        return response(
            CxmlBuilder::sayWithHangup('IVR system is not yet configured.', true),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
