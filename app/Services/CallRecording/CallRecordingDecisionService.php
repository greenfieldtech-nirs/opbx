<?php

declare(strict_types=1);

namespace App\Services\CallRecording;

use App\Models\CloudonixSettings;

/**
 * Decides whether a given call leg should be recorded, based on the
 * organization's `call_recording_mode` setting and the call's category.
 *
 * Recording itself happens via the CXML `<Dial>` verb's `record` attribute
 * (not a separate `<Record>` verb). Cloudonix notifies completion via the
 * `recordingStatusCallback` URL, handled by
 * CloudonixWebhookController::recordingStatus().
 *
 * @see https://developers.cloudonix.com/Documentation/voiceApplication/Verb/dial
 */
class CallRecordingDecisionService
{
    /**
     * Categories a call leg can fall into, matching the vocabulary of the
     * `call_recording_mode` setting. Callers resolve this from context:
     * - 'outbound': an internal extension dialing an external destination via a trunk.
     * - 'internal': extension-to-extension (or ring group) traffic that never left the PBX.
     * - 'inbound': an external caller reaching an extension/ring group/DID.
     */
    public function resolve(?int $organizationId, string $callCategory): CallRecordingDecision
    {
        if (! $organizationId) {
            return CallRecordingDecision::none();
        }

        $settings = CloudonixSettings::where('organization_id', $organizationId)->first();

        if (! $settings || ! $this->shouldRecord($settings->call_recording_mode, $callCategory)) {
            return CallRecordingDecision::none();
        }

        $baseUrl = rtrim($settings->effective_webhook_base_url ?? config('app.url'), '/');

        return new CallRecordingDecision(
            record: 'record-from-answer',
            recordingStatusCallback: "{$baseUrl}/api/webhooks/cloudonix/recording-status",
        );
    }

    private function shouldRecord(string $mode, string $callCategory): bool
    {
        return match ($mode) {
            'inbound' => $callCategory === 'inbound',
            'outbound' => $callCategory === 'outbound',
            'internal' => $callCategory === 'internal',
            'inbound_outbound' => in_array($callCategory, ['inbound', 'outbound'], true),
            'all' => true,
            default => false, // 'disabled' and any unrecognized value
        };
    }
}
