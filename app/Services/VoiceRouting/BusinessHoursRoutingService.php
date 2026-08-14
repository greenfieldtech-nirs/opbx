<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\BusinessHoursActionType;
use App\Models\BusinessHoursSchedule;
use App\Models\Extension;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CallRecording\CallRecordingDecisionService;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Business Hours Routing Service
 *
 * Handles time-based routing decisions based on business hours schedules.
 * Determines if current time is within open hours and routes accordingly.
 */
class BusinessHoursRoutingService
{
    public function __construct(
        private readonly VoiceRoutingCacheService $cache,
        private readonly CallRecordingDecisionService $recordingDecision
    ) {}

    /**
     * Check business hours and route accordingly.
     *
     * @param int $organizationId The organization ID
     * @param string $callSid The call SID for logging
     * @param Request $request The incoming request
     * @return Response|null CXML response if business hours routing applies, null otherwise
     */
    public function checkBusinessHours(int $organizationId, string $callSid, Request $request): ?Response
    {
        $schedule = $this->cache->getActiveBusinessHoursSchedule($organizationId);

        if (! $schedule) {
            return null;
        }

        $currentAction = $schedule->getCurrentRouting();

        if (! empty($currentAction)) {
            Log::info('BusinessHoursRoutingService: Routing via business hours action', [
                'call_sid' => $callSid,
                'action' => $currentAction,
                'is_open' => $schedule->isCurrentlyOpen(),
            ]);

            return $this->routeToBusinessHoursAction($currentAction, $organizationId, $callSid, $request);
        }

        return null;
    }

    /**
     * Route to business hours action destination.
     *
     * @param string|array $action The routing action (string for backward compatibility, array for new format)
     * @param int $organizationId The organization ID
     * @param string $callSid The call SID
     * @param Request $request The incoming request
     * @return Response CXML response
     */
    private function routeToBusinessHoursAction($action, int $organizationId, string $callSid, Request $request): Response
    {
        // Handle array format from new BusinessHoursSchedule
        if (is_array($action)) {
            return $this->routeArrayAction($action, $organizationId, $callSid, $request);
        }

        // Handle legacy string format (backward compatibility)
        Log::warning('BusinessHoursRoutingService: Legacy string action format', [
            'call_sid' => $callSid,
            'action' => $action,
        ]);

        return response(
            CxmlBuilder::unavailable('Business hours configuration needs update'),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Route using array-format action configuration.
     *
     * @param array $action The action configuration array
     * @param int $organizationId The organization ID
     * @param string $callSid The call SID
     * @return Response CXML response
     */
    private function routeArrayAction(array $action, int $organizationId, string $callSid, Request $request): Response
    {
        $type = $action['type'] ?? 'unknown';
        $config = $action['config'] ?? [];

        Log::info('BusinessHoursRoutingService: Routing to array action', [
            'call_sid' => $callSid,
            'type' => $type,
            'config' => $config,
        ]);

        return match ($type) {
            'extension' => $this->routeToExtension($config['extension_id'] ?? null, $organizationId, $callSid, $request),
            'ring_group' => $this->routeToRingGroup($config['ring_group_id'] ?? null, $organizationId, $callSid, $request),
            'conference_room' => $this->routeToConferenceRoom($config['conference_room_id'] ?? null, $organizationId, $callSid),
            'ivr_menu' => $this->routeToIvrMenu($config['ivr_menu_id'] ?? null, $organizationId, $callSid),
            'voicemail' => response(CxmlBuilder::sendToVoicemail(), 200, ['Content-Type' => 'application/xml']),
            'hangup' => response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']),
            default => response(
                CxmlBuilder::unavailable('Business hours action not configured'),
                200,
                ['Content-Type' => 'application/xml']
            ),
        };
    }

    /**
     * Route to extension.
     */
    private function routeToExtension(?int $extensionId, int $organizationId, string $callSid, Request $request): Response
    {
        if (! $extensionId) {
            return response(
                CxmlBuilder::unavailable('Extension not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $extensionId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (! $extension) {
            Log::warning('BusinessHoursRoutingService: Extension not found or inactive', [
                'call_sid' => $callSid,
                'extension_id' => $extensionId,
            ]);

            return response(
                CxmlBuilder::unavailable('Extension not available'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $sipUri = $extension->getSipUri();

        if (! $sipUri) {
            return response(
                CxmlBuilder::unavailable('Extension has no SIP configuration'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $recording = $this->recordingDecision->resolve($organizationId, $request->input('_call_category', 'inbound'));

        return response(
            CxmlBuilder::simpleDial($sipUri, null, null, null, null, $recording->record, $recording->recordingStatusCallback),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Route to ring group.
     */
    private function routeToRingGroup(?int $ringGroupId, int $organizationId, string $callSid, Request $request): Response
    {
        if (! $ringGroupId) {
            return response(
                CxmlBuilder::unavailable('Ring group not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (! $ringGroup) {
            Log::warning('BusinessHoursRoutingService: Ring group not found', [
                'call_sid' => $callSid,
                'ring_group_id' => $ringGroupId,
            ]);

            return response(
                CxmlBuilder::unavailable('Ring group not available'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $members = $ringGroup->getMembers()->filter(fn ($ext) => $ext->isActive());
        $sipUris = $members->map(fn ($ext) => $ext->getSipUri())->filter()->values()->toArray();

        if (empty($sipUris)) {
            return response(
                CxmlBuilder::unavailable('No active members in ring group'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $recording = $this->recordingDecision->resolve($organizationId, $request->input('_call_category', 'inbound'));

        return response(
            CxmlBuilder::dialRingGroup($sipUris, $ringGroup->timeout, null, $recording->record, $recording->recordingStatusCallback),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Route to conference room.
     */
    private function routeToConferenceRoom(?int $conferenceRoomId, int $organizationId, string $callSid): Response
    {
        if (! $conferenceRoomId) {
            return response(
                CxmlBuilder::unavailable('Conference room not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Implementation would need ConferenceRoom model - stub for now
        return response(
            CxmlBuilder::unavailable('Conference room routing not yet implemented'),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Route to IVR menu.
     */
    private function routeToIvrMenu(?int $ivrMenuId, int $organizationId, string $callSid): Response
    {
        if (! $ivrMenuId) {
            return response(
                CxmlBuilder::unavailable('IVR menu not configured'),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        // Would delegate to IvrRoutingStrategy - stub for now
        return response(
            CxmlBuilder::unavailable('IVR menu routing via business hours not yet implemented'),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
