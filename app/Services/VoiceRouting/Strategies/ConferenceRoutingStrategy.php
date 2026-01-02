<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConferenceRoutingStrategy implements RoutingStrategy
{
    public function canHandle(ExtensionType $type): bool
    {
        return $type === ExtensionType::CONFERENCE;
    }

    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        /** @var ConferenceRoom $room */
        $room = $destination['conference_room'] ?? null;

        if (!$room) {
            return response(CxmlBuilder::unavailable('Conference room not found'), 200, ['Content-Type' => 'text/xml']);
        }

        if ($room->status !== \App\Enums\UserStatus::ACTIVE) {
            return response(CxmlBuilder::unavailable('Conference room is closed'), 200, ['Content-Type' => 'text/xml']);
        }

        // Use a safe unique identifier for the conference room
        $identifier = sprintf('conf_%d', $room->id);

        return response(
            CxmlBuilder::joinConference(
                $identifier,
                $room->max_participants,
                $room->mute_on_entry ?? false,
                $room->announce_join_leave ?? false
            ),
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
