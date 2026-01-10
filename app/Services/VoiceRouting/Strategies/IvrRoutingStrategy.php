<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
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

    public function route(Request $request, DidNumber $did, array $destination, ?string $errorMessage = null): Response
    {
        $callSid = $request->input('CallSid');
        $orgId = (int) $request->input('_organization_id');

        // Extract IVR menu from destination
        $ivrMenu = $destination['ivr_menu'] ?? null;

        if (!$ivrMenu instanceof IvrMenu) {
            Log::error('IVR Routing: Invalid IVR menu destination', [
                'call_sid' => $callSid,
                'destination_keys' => array_keys($destination)
            ]);
            return response(
                CxmlBuilder::sayWithHangup('IVR menu configuration error.', true),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        // Get the organization's webhook base URL for consistent callback URLs
        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $orgId)->first();
        $baseUrl = $cloudonixSettings && $cloudonixSettings->webhook_base_url
            ? rtrim($cloudonixSettings->webhook_base_url, '/')
            : $request->getSchemeAndHttpHost();

        // Initialize or get current call state
        $callState = $this->ivrStateService->initializeCallState($callSid, $ivrMenu->id);

        // Generate CXML for IVR menu presentation
        return $this->generateIvrMenuResponse($request, $ivrMenu, $callState, $baseUrl, $errorMessage);
    }

    /**
     * Generate CXML response for IVR menu presentation.
     */
    private function generateIvrMenuResponse(Request $request, IvrMenu $ivrMenu, array $callState, string $baseUrl, ?string $errorMessage = null): Response
    {
        $callSid = $request->input('CallSid');

        // Determine what to play/say
        $audioContent = $this->getAudioContent($request, $ivrMenu, $baseUrl, $errorMessage);

        // Create Gather verb for DTMF collection
        $relativeUrl = route('voice.ivr-input', [], false) . '?menu_id=' . $ivrMenu->id;
        $gatherAction = $baseUrl . $relativeUrl;
        $interDigitTimeout = $ivrMenu->inter_digit_timeout ?? 2; // Default 2 seconds
        $maxTimeout = $ivrMenu->max_timeout ?? 3; // Default 3 seconds
        $gatherFinishOnKey = '#'; // End input on #

        $cxml = CxmlBuilder::gather(
            $audioContent,
            $gatherAction,
            $interDigitTimeout,
            $gatherFinishOnKey,
            1, // min digits
            10, // max digits
            $maxTimeout
        );

        Log::info('IVR Routing: Presenting menu to caller', [
            'call_sid' => $callSid,
            'ivr_menu_id' => $ivrMenu->id,
            'ivr_menu_name' => $ivrMenu->name,
            'gather_action' => $gatherAction,
            'turn_count' => $callState['turn_count'],
            'max_turns' => $ivrMenu->max_turns,
        ]);

        return response($cxml, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Get audio content for the IVR menu (file or TTS).
     */
    private function getAudioContent(Request $request, IvrMenu $ivrMenu, string $baseUrl, ?string $errorMessage = null): string
    {
        $audioContent = '';

        // Prepend error message if provided
        if ($errorMessage) {
            $audioContent .= CxmlBuilder::sayXml($errorMessage);
        }

        // Priority: audio file > TTS text > default message
        if ($ivrMenu->audio_file_path) {
            $audioUrl = $this->resolveAudioUrl($request, $ivrMenu->audio_file_path, $baseUrl);
            $audioContent .= CxmlBuilder::playXml($audioUrl);
        } elseif ($ivrMenu->tts_text) {
            // Use the selected voice, fallback to Cloudonix-Neural:Zoe if not set
            $voice = $ivrMenu->tts_voice ?: 'Cloudonix-Neural:Zoe';
            $audioContent .= CxmlBuilder::sayXml($ivrMenu->tts_text, $voice);
        } else {
            // Default fallback message
            $audioContent .= CxmlBuilder::sayXml('Please enter the number for your desired option.', 'Cloudonix-Neural:Zoe');
        }

        return $audioContent;
    }

    /**
     * Resolve audio file path to a full URL that Cloudonix can fetch.
     */
    private function resolveAudioUrl(Request $request, string $audioPath, string $baseUrl): string
    {
        // If it's already a full URL, use it as-is (remote URL scenario)
        if (str_starts_with($audioPath, 'http://') || str_starts_with($audioPath, 'https://')) {
            return $audioPath;
        }

        // For MinIO-stored files, use the public proxy route
        $orgId = (int) $request->input('_organization_id');
        return $baseUrl . '/api/storage/recordings/' . $orgId . '/' . urlencode($audioPath);
    }
}
