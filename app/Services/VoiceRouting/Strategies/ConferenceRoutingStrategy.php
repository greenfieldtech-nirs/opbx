<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixSettingsResolver;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Joins a caller to a conference room, applying the room's settings:
 * PIN / host PIN (collected with <Gather>, checked server-side), wait-for-host,
 * hold music, mute on entry, join/leave prompts, recording and capacity.
 */
class ConferenceRoutingStrategy implements RoutingStrategy
{
    public const MAX_PIN_ATTEMPTS = 3;

    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::CONFERENCE;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var ConferenceRoom|null $room */
        $room = $destination['conference_room'] ?? null;

        if (! $room) {
            return $this->xml(CxmlBuilder::unavailable('Conference room not found'));
        }

        if ($room->status !== UserStatus::ACTIVE) {
            return $this->xml(CxmlBuilder::unavailable('Conference room is closed'));
        }

        if ($this->askForPin($room)) {
            return $this->pinPrompt($room, (int) $request->input('_organization_id', $room->organization_id), 1);
        }

        if ($room->pin_required) {
            // PIN required but none stored: fail closed rather than leave the room open.
            Log::warning('ConferenceRoutingStrategy: PIN required but no PIN configured', ['conference_room_id' => $room->id]);

            return $this->xml(CxmlBuilder::unavailable('Conference room is not available'));
        }

        return $this->join($room, host: false);
    }

    /**
     * Handle the digits collected by the PIN <Gather>.
     */
    public function handlePinCallback(Request $request): Response
    {
        $organizationId = (int) $request->input('_organization_id');
        $attempt = max(1, (int) $request->query('attempt', 1));

        $room = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', (int) $request->query('room_id'))
            ->where('organization_id', $organizationId)
            ->first();

        if (! $room || $room->status !== UserStatus::ACTIVE) {
            return $this->xml(CxmlBuilder::unavailable('Conference room is closed'));
        }

        $digits = (string) $request->input('Digits', '');

        if ($digits !== '' && $this->matches($room->host_pin, $digits)) {
            return $this->join($room, host: true);
        }

        if (! $room->pin_required) {
            // Optional host prompt (wait-for-host without a participant PIN): anyone else just joins.
            return $this->join($room, host: false);
        }

        if ($digits !== '' && $this->matches($room->pin, $digits)) {
            return $this->join($room, host: false);
        }

        Log::info('ConferenceRoutingStrategy: wrong conference PIN', ['conference_room_id' => $room->id, 'attempt' => $attempt]);

        if ($attempt >= self::MAX_PIN_ATTEMPTS) {
            return $this->xml(CxmlBuilder::sayWithHangup('Too many incorrect attempts. Goodbye.', true));
        }

        return $this->pinPrompt($room, $organizationId, $attempt + 1, 'That PIN was not correct. ');
    }

    /** A PIN prompt is needed when participants must enter a PIN, or when a host has to be identified. */
    private function askForPin(ConferenceRoom $room): bool
    {
        $hostGate = $room->wait_for_host && filled($room->host_pin);

        return ($room->pin_required && (filled($room->pin) || filled($room->host_pin))) || $hostGate;
    }

    private function pinPrompt(ConferenceRoom $room, int $organizationId, int $attempt, string $prefix = ''): Response
    {
        $baseUrl = app(CloudonixSettingsResolver::class)->webhookBaseUrl($organizationId);
        $action = $baseUrl.route('voice.conference-pin', [], false)."?room_id={$room->id}&attempt={$attempt}";

        $prompt = $room->pin_required
            ? $prefix.'Please enter the conference PIN followed by the pound key.'
            : 'If you are the host, enter your PIN followed by the pound key. Otherwise, please hold.';

        $builder = new CxmlBuilder;
        $builder->addGather(CxmlBuilder::sayXml($prompt), $action, timeout: 8, finishOnKey: '#', minDigits: 1, maxDigits: 20);

        if ($room->pin_required) {
            $builder->say('We did not receive a PIN. Goodbye.')->hangup();
        } else {
            // Optional prompt: no input means a regular participant.
            $this->appendConference($builder, $room, host: false);
        }

        return $this->xml($builder->build());
    }

    private function join(ConferenceRoom $room, bool $host): Response
    {
        $builder = new CxmlBuilder;
        $this->appendConference($builder, $room, $host);

        return $this->xml($builder->build());
    }

    private function appendConference(CxmlBuilder $builder, ConferenceRoom $room, bool $host): void
    {
        // Waiting for a host only makes sense when a host can identify themselves.
        $waitForHost = $room->wait_for_host && filled($room->host_pin);
        $record = $room->recording_enabled && $room->recording_auto_start;

        $builder->dialConferenceRoom(
            sprintf('conf_%d', $room->id),
            startOnEnter: $host || ! $waitForHost,
            maxParticipants: $room->max_participants,
            muted: ! $host && $room->mute_on_entry,
            holdMusic: (bool) $room->music_on_hold,
            prompts: (bool) $room->announce_join_leave,
            record: $record,
            recordingStatusCallback: $record
                ? app(CloudonixSettingsResolver::class)->webhookBaseUrl((int) $room->organization_id).'/api/webhooks/cloudonix/recording-status'
                : null,
        );
    }

    private function matches(?string $expected, string $given): bool
    {
        return filled($expected) && hash_equals((string) $expected, $given);
    }

    private function xml(string $cxml): Response
    {
        return response($cxml, 200, ['Content-Type' => 'application/xml']);
    }
}
