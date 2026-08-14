<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Services\CallRecording\CallRecordingDecisionService;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ForwardRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::FORWARD;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var Extension $extension */
        $extension = $destination['extension'] ?? null;

        if (!$extension) {
            return response(CxmlBuilder::unavailable('Forward extension not found'), 200, ['Content-Type' => 'application/xml']);
        }

        $config = $extension->configuration ?? [];
        $forwardTo = $config['forward_to'] ?? null;

        if (!$forwardTo) {
            return response(CxmlBuilder::unavailable('Forward destination not configured'), 200, ['Content-Type' => 'application/xml']);
        }

        $recording = app(CallRecordingDecisionService::class)->resolve(
            (int) $request->input('_organization_id'),
            $request->input('_call_category', 'inbound')
        );

        // Case 1: SIP URI (starts with sip:)
        if (str_starts_with(strtolower($forwardTo), 'sip:')) {
            return response(
                CxmlBuilder::dialExtension($forwardTo, null, $recording->record, $recording->recordingStatusCallback),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Case 2: External phone number (E.164 format, starts with +)
        if (preg_match('/^\+[1-9]\d{1,14}$/', $forwardTo)) {
            return response(
                CxmlBuilder::simpleDial($forwardTo, null, null, null, null, $recording->record, $recording->recordingStatusCallback),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Case 3: Internal extension number
        $organizationId = $extension->organization_id;

        $targetExtension = Extension::where('organization_id', $organizationId)
            ->where('extension_number', $forwardTo)
            ->first();

        if (!$targetExtension) {
            return response(CxmlBuilder::unavailable("Target extension $forwardTo not found"), 200, ['Content-Type' => 'application/xml']);
        }

        if (!$targetExtension->isActive()) {
            return response(CxmlBuilder::unavailable("Target extension $forwardTo is inactive"), 200, ['Content-Type' => 'application/xml']);
        }

        $sipUri = $targetExtension->getSipUri();
        if (!$sipUri) {
            return response(CxmlBuilder::unavailable("Target extension $forwardTo has no valid SIP URI"), 200, ['Content-Type' => 'application/xml']);
        }

        return response(
            CxmlBuilder::dialExtension($sipUri, null, $recording->record, $recording->recordingStatusCallback),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
