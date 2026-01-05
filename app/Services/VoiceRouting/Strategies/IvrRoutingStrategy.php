<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\IvrMenu;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\IvrStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IvrRoutingStrategy implements RoutingStrategy
{
    public function __construct(
        private readonly IvrStateService $ivrStateService
    ) {}

    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::IVR;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        $callSid = $request->input('CallSid');
        $orgId = (int) $request->input('_organization_id');

        // Extract IVR menu from destination
        $ivrMenu = $destination['ivr_menu'] ?? null;

        if (!$ivrMenu instanceof IvrMenu) {
            Log::error('IVR Routing: Invalid IVR menu destination', [
                'call_sid' => $callSid,
                'destination' => $destination
            ]);
            return response(
                CxmlBuilder::sayWithHangup('IVR menu configuration error.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Initialize or get current call state
        $callState = $this->ivrStateService->initializeCallState($callSid, $ivrMenu->id);

        // Generate CXML for IVR menu presentation
        return $this->generateIvrMenuResponse($request, $ivrMenu, $callState);
    }

    /**
     * Generate CXML response for IVR menu presentation.
     */
    private function generateIvrMenuResponse(Request $request, IvrMenu $ivrMenu, array $callState): Response
    {
        $callSid = $request->input('CallSid');

        // Determine what to play/say
        $audioContent = $this->getAudioContent($ivrMenu);

        // Create Gather verb for DTMF collection
        $gatherAction = route('voice.ivr-input', [], false) . '?menu_id=' . $ivrMenu->id;
        $gatherTimeout = 10; // 10 seconds
        $gatherFinishOnKey = '#'; // End input on #

        $cxml = CxmlBuilder::gather(
            $audioContent,
            $gatherAction,
            $gatherTimeout,
            $gatherFinishOnKey,
            1, // min digits
            10 // max digits
        );

        Log::info('IVR Routing: Presenting menu to caller', [
            'call_sid' => $callSid,
            'ivr_menu_id' => $ivrMenu->id,
            'ivr_menu_name' => $ivrMenu->name,
            'turn_count' => $callState['turn_count'],
            'max_turns' => $ivrMenu->max_turns,
        ]);

        return response($cxml, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Get audio content for the IVR menu (file or TTS).
     */
    private function getAudioContent(IvrMenu $ivrMenu): string
    {
        // Priority: audio file > TTS text > default message
        if ($ivrMenu->audio_file_path) {
            return CxmlBuilder::play($ivrMenu->audio_file_path);
        }

        if ($ivrMenu->tts_text) {
            return CxmlBuilder::say($ivrMenu->tts_text, true);
        }

        // Default fallback message
        return CxmlBuilder::say('Please enter the number for your desired option.', true);
    }
}
