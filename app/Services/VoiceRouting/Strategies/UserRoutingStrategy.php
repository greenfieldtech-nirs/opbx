<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::USER;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        // $destination['extension'] should hold the Extension model
        /** @var Extension $extension */
        $extension = $destination['extension'] ?? null;

        if (!$extension) {
            return response(CxmlBuilder::unavailable('Extension not found'), 200, ['Content-Type' => 'text/xml']);
        }

        // Check if extension is active
        if (!$extension->isActive()) {
            return response(CxmlBuilder::unavailable('Extension is not available'), 200, ['Content-Type' => 'text/xml']);
        }

        // E.164 normalization for caller ID if needed, or use From
        $from = $request->input('From');

        // Check if user has SIP credentials or a phone number to dial
        // For PBX User, we typically dial the SIP URI associated with the user/extension
        // Assuming formatting: sip:EXTENSION@DOMAIN

        $sipUri = sprintf('sip:%s@%s', $extension->extension_number, $request->input('Domain'));

        // We can uses CxmlBuilder::dialSip($sipUri, $from) if available, or simpleDial if it handles SIP.
        // Looking at CxmlBuilder usage in existing controller, it uses simpleDial($normalizedTo, $from).
        // Since we are routing TO an internal extension, we should probably Dial the SIP user.
        // However, the current controller logic for internal calls uses:
        // CxmlBuilder::simpleDial($normalizedTo, $from) for outbound
        // But for internal extension-to-extension, let's see what the legacy controller did.

        // Legacy controller:
        // return $this->routeToUserExtension($destinationExtension, $from, $to, $callSid);
        // which did: return $this->cxmlResponse(CxmlBuilder::dialSip($sipEndpoint, $from, $timeout));

        return response(
            CxmlBuilder::dialExtension($sipUri, 30),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
