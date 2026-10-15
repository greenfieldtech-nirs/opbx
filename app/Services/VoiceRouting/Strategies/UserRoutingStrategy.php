<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\CallDetailRecord;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\CallRecording\CallRecordingDecisionService;
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

        if (! $extension) {
            return response(CxmlBuilder::unavailable('Extension not found'), 200, ['Content-Type' => 'application/xml']);
        }

        // Check if extension is active
        if (! $extension->isActive()) {
            return response(CxmlBuilder::unavailable('Extension is not available'), 200, ['Content-Type' => 'application/xml']);
        }

        // A single-target dial (DID, IVR option, business hours, fallbacks): this extension takes the call.
        CallDetailRecord::recordAnsweringExtension(
            (int) $request->input('_organization_id'),
            $request->input('Session') ?: $request->input('CallSid'),
            (string) $extension->extension_number,
        );

        // Check if this user extension has forwarding configuration
        $config = $extension->configuration ?? [];
        $forwardTo = $config['forward_to'] ?? null;

        if ($forwardTo) {
            // Delegate to forwarding logic
            $forwardStrategy = new ForwardRoutingStrategy;

            return $forwardStrategy->route($request, $did, $destination);
        }

        // E.164 normalization for caller ID if needed, or use From
        $from = $request->input('From');

        // For internal extension dialing, use just the extension number
        // This creates <Number> tags instead of <Sip> tags for proper internal routing

        $recording = app(CallRecordingDecisionService::class)->resolve(
            (int) $request->input('_organization_id'),
            $request->input('_call_category', 'inbound')
        );

        return response(
            CxmlBuilder::dialExtension(
                $extension->extension_number,
                30,
                $recording->record,
                $recording->recordingStatusCallback,
            ),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
