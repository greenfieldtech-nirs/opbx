<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\AiAssistantStatus;
use App\Enums\AlbsStatus;
use App\Enums\BusinessHoursActionType;
use App\Enums\ExtensionType;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\Strategies\CallTrackingRoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Inbound Routing Service
 *
 * Handles inbound call routing logic including DID-based routing,
 * time-based routing via business hours, and destination resolution.
 */
class InboundRoutingService
{
    public function __construct(
        private readonly VoiceRoutingCacheService $cache,
        private readonly ExtensionRoutingService $extensionRoutingService,
        private readonly VoiceRoutingStrategyExecutor $strategyExecutor,
        private readonly CallTrackingRoutingStrategy $callTrackingStrategy
    ) {}

    /**
     * Check if a phone number is assigned as a DID in the system.
     *
     * @param  string  $phoneNumber  The phone number to check
     * @param  int  $orgId  The organization ID
     * @return bool True if the phone number is assigned as an active DID
     */
    public function isAssignedPhoneNumber(string $phoneNumber, int $orgId): bool
    {
        $did = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('phone_number', $phoneNumber)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        return $did !== null;
    }

    /**
     * Handle DID-based routing if a DID is found for the destination number.
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $to  The destination phone number
     * @param  int  $orgId  The organization ID
     * @return Response|null CXML response if DID routing applies, null otherwise
     */
    public function handleDidRouting(Request $request, string $to, int $orgId): ?Response
    {
        Log::debug('InboundRoutingService: Checking for DID', [
            'to' => $to,
            'org_id' => $orgId,
        ]);

        $did = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('phone_number', $to)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        Log::info('InboundRoutingService: DID lookup result', [
            'did_found' => $did !== null,
            'did_id' => $did?->id,
            'did_phone_number' => $did?->phone_number,
            'did_routing_type' => $did?->routing_type,
        ]);

        if ($did) {
            Log::debug('InboundRoutingService: Routing to DID destination', [
                'did_id' => $did->id,
                'routing_type' => $did->routing_type,
            ]);

            return $this->routeDidCall($request, $did);
        }

        return null;
    }

    /**
     * Route a call based on DID configuration.
     *
     * @param  Request  $request  The incoming request
     * @param  DidNumber  $did  The DID number configuration
     * @return Response CXML response
     */
    public function routeDidCall(Request $request, DidNumber $did): Response
    {
        Log::info('InboundRoutingService: routeDidCall started', [
            'did_id' => $did->id,
            'did_phone_number' => $did->phone_number,
            'routing_type' => $did->routing_type,
        ]);

        if ($did->routing_type === 'call_tracking') {
            return $this->callTrackingStrategy->route($request, $did, []);
        }

        $destination = $this->resolveDidDestination($did);

        if (empty($destination)) {
            Log::warning('InboundRoutingService: No valid destination found for DID', [
                'did_id' => $did->id,
                'routing_type' => $did->routing_type,
            ]);

            return $this->createErrorResponse('Destination not configured');
        }

        Log::info('InboundRoutingService: Routing destination populated', [
            'did_id' => $did->id,
            'routing_type' => $did->routing_type,
            'destination_keys' => array_keys($destination),
        ]);

        $extensionType = $this->determineExtensionType($did, $destination);

        if (! $extensionType) {
            return $this->createErrorResponse('Unsupported routing configuration');
        }

        return $this->strategyExecutor->executeStrategy($extensionType, $request, $did, $destination);
    }

    /**
     * Resolve destination based on DID routing type.
     *
     * @param  DidNumber  $did  The DID number
     * @return array<string, mixed> The destination array with loaded models
     */
    private function resolveDidDestination(DidNumber $did): array
    {
        $destination = [];

        match ($did->routing_type) {
            'extension' => $this->resolveExtensionDestination($did, $destination),
            'ring_group' => $this->resolveRingGroupDestination($did, $destination),
            'conference_room' => $this->resolveConferenceRoomDestination($did, $destination),
            'ivr_menu', 'ivr' => $this->resolveIvrMenuDestination($did, $destination),
            'ai_assistant' => $this->resolveAiAssistantDestination($did, $destination),
            'ai_load_balancer' => $this->resolveAiLoadBalancerDestination($did, $destination),
            'business_hours' => $this->resolveBusinessHoursDestination($did, $destination),
            'call_tracking' => $destination['call_tracking_campaign_id'] = $did->routing_config['call_tracking_campaign_id'] ?? null,
            default => null,
        };

        return $destination;
    }

    /**
     * Resolve extension destination for DID.
     */
    private function resolveExtensionDestination(DidNumber $did, array &$destination): void
    {
        $extension = $did->getExtensionAttribute();
        if ($extension) {
            $destination['extension'] = $extension;
        }
    }

    /**
     * Resolve ring group destination for DID.
     */
    private function resolveRingGroupDestination(DidNumber $did, array &$destination): void
    {
        $ringGroup = $did->getRingGroupAttribute();
        if ($ringGroup) {
            $destination['ring_group'] = $ringGroup;
        }
    }

    /**
     * Resolve conference room destination for DID.
     */
    private function resolveConferenceRoomDestination(DidNumber $did, array &$destination): void
    {
        $conferenceRoom = $did->getConferenceRoomAttribute();
        if ($conferenceRoom) {
            $destination['conference_room'] = $conferenceRoom;
        }
    }

    /**
     * Resolve IVR menu destination for DID.
     */
    private function resolveIvrMenuDestination(DidNumber $did, array &$destination): void
    {
        $ivrMenu = $did->getIvrMenuAttribute();
        if ($ivrMenu) {
            $destination['ivr_menu'] = $ivrMenu;
        }
    }

    /**
     * Resolve AI assistant destination for DID.
     */
    private function resolveAiAssistantDestination(DidNumber $did, array &$destination): void
    {
        $aiAssistant = $did->getAiAssistantAttribute();

        Log::info('InboundRoutingService: AI assistant routing lookup', [
            'did_id' => $did->id,
            'ai_assistant_found' => $aiAssistant !== null,
        ]);

        if ($aiAssistant) {
            $destination['ai_assistant'] = $aiAssistant;
        }
    }

    /**
     * Resolve AI load balancer destination for DID.
     */
    private function resolveAiLoadBalancerDestination(DidNumber $did, array &$destination): void
    {
        $aiLoadBalancer = $did->getAiLoadBalancerAttribute();

        Log::info('InboundRoutingService: AI load balancer routing lookup', [
            'did_id' => $did->id,
            'ai_load_balancer_found' => $aiLoadBalancer !== null,
        ]);

        if ($aiLoadBalancer) {
            $destination['ai_load_balancer'] = $aiLoadBalancer;
        }
    }

    /**
     * Resolve business hours destination for DID.
     */
    private function resolveBusinessHoursDestination(DidNumber $did, array &$destination): void
    {
        Log::info('InboundRoutingService: Processing business_hours routing', [
            'did_id' => $did->id,
        ]);

        $schedule = $did->getBusinessHoursScheduleAttribute();

        Log::info('InboundRoutingService: Business Hours schedule lookup', [
            'did_id' => $did->id,
            'schedule_found' => $schedule !== null,
        ]);

        if ($schedule) {
            try {
                $actionType = $schedule->getCurrentRoutingType();
                $targetId = $schedule->getCurrentRoutingTargetId();

                Log::info('InboundRoutingService: Business hours routing', [
                    'did_id' => $did->id,
                    'action_type' => $actionType->value,
                    'target_id' => $targetId,
                ]);

                $this->resolveBusinessHoursTarget($did, $actionType, $targetId, $destination);
            } catch (\Exception $e) {
                Log::error('InboundRoutingService: Exception in schedule methods', [
                    'did_id' => $did->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve business hours target based on action type.
     */
    private function resolveBusinessHoursTarget(
        DidNumber $did,
        BusinessHoursActionType $actionType,
        ?string $targetId,
        array &$destination
    ): void {
        match ($actionType) {
            BusinessHoursActionType::EXTENSION => $this->resolveBusinessHoursExtension($did, $targetId, $destination),
            BusinessHoursActionType::RING_GROUP => $this->resolveBusinessHoursRingGroup($did, $targetId, $destination),
            BusinessHoursActionType::CONFERENCE_ROOM => $this->resolveBusinessHoursConferenceRoom($did, $targetId, $destination),
            BusinessHoursActionType::IVR_MENU => $this->resolveBusinessHoursIvrMenu($did, $targetId, $destination),
            BusinessHoursActionType::AI_ASSISTANT => $this->resolveBusinessHoursAiAssistant($did, $targetId, $destination),
            BusinessHoursActionType::AI_LOAD_BALANCER => $this->resolveBusinessHoursAiLoadBalancer($did, $targetId, $destination),
            default => Log::warning('InboundRoutingService: Business Hours action type not handled', [
                'did_id' => $did->id,
                'action_type' => $actionType->value,
            ]),
        };
    }

    /**
     * Resolve business hours extension target.
     */
    private function resolveBusinessHoursExtension(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $extensionId = $this->parseTargetId($targetId, 'ext');

        if ($extensionId) {
            $extension = Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $extensionId)
                ->where('organization_id', $did->organization_id)
                ->where('status', 'active')
                ->first();

            if ($extension) {
                $destination['extension'] = $extension;
            }
        }
    }

    /**
     * Resolve business hours ring group target.
     */
    private function resolveBusinessHoursRingGroup(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $ringGroupId = $this->parseTargetId($targetId, 'rg');

        if ($ringGroupId) {
            $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $ringGroupId)
                ->where('organization_id', $did->organization_id)
                ->where('status', 'active')
                ->first();

            if ($ringGroup) {
                $destination['ring_group'] = $ringGroup;
            }
        }
    }

    /**
     * Resolve business hours conference room target.
     */
    private function resolveBusinessHoursConferenceRoom(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $conferenceRoomId = $this->parseTargetId($targetId, 'conf');

        if ($conferenceRoomId) {
            $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $conferenceRoomId)
                ->where('organization_id', $did->organization_id)
                ->first();

            if ($conferenceRoom) {
                $destination['conference_room'] = $conferenceRoom;
            }
        }
    }

    /**
     * Resolve business hours IVR menu target.
     */
    private function resolveBusinessHoursIvrMenu(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $ivrMenuId = $this->parseTargetId($targetId, 'ivr');

        if ($ivrMenuId) {
            $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $ivrMenuId)
                ->where('organization_id', $did->organization_id)
                ->where('status', 'active')
                ->first();

            if ($ivrMenu) {
                $destination['ivr_menu'] = $ivrMenu;
            }
        }
    }

    /**
     * Resolve business hours AI assistant target.
     */
    private function resolveBusinessHoursAiAssistant(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $aiAssistantId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^ai-(\d+)$/', $targetId, $matches)) {
            $aiAssistantId = (int) $matches[1];
        }

        if ($aiAssistantId) {
            $aiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $aiAssistantId)
                ->where('organization_id', $did->organization_id)
                ->where('status', AiAssistantStatus::ACTIVE)
                ->first();

            if ($aiAssistant) {
                $destination['ai_assistant'] = $aiAssistant;
            }
        }
    }

    /**
     * Resolve business hours AI load balancer target.
     */
    private function resolveBusinessHoursAiLoadBalancer(DidNumber $did, ?string $targetId, array &$destination): void
    {
        if (! $targetId) {
            return;
        }

        $albsId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^alb[s]?-(\d+)$/', $targetId, $matches)) {
            $albsId = (int) $matches[1];
        }

        if ($albsId) {
            $aiLoadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $albsId)
                ->where('organization_id', $did->organization_id)
                ->where('status', AlbsStatus::ACTIVE)
                ->first();

            if ($aiLoadBalancer) {
                $destination['ai_load_balancer'] = $aiLoadBalancer;
            }
        }
    }

    /**
     * Parse target ID from various formats.
     *
     * @param  string  $targetId  The target ID string
     * @param  string  $prefix  The expected prefix (e.g., 'ext', 'rg', 'conf', 'ivr')
     * @return int|null The parsed ID or null if invalid
     */
    private function parseTargetId(string $targetId, string $prefix): ?int
    {
        if (is_numeric($targetId)) {
            return (int) $targetId;
        }

        if (preg_match('/^'.$prefix.'-(\d+)$/', $targetId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Determine the extension type from a DID destination.
     *
     * @param  DidNumber  $did  The DID number
     * @param  array<string, mixed>  $destination  The destination array
     * @return ExtensionType|null The extension type or null if cannot determine
     */
    public function determineExtensionType(DidNumber $did, array $destination): ?ExtensionType
    {
        // If destination contains extension, use its type
        if (isset($destination['extension'])) {
            return $destination['extension']->type;
        }

        // If destination contains ring_group, return RING_GROUP
        if (isset($destination['ring_group'])) {
            return ExtensionType::RING_GROUP;
        }

        // If destination contains conference_room, return CONFERENCE
        if (isset($destination['conference_room'])) {
            return ExtensionType::CONFERENCE;
        }

        // If destination contains ivr_menu, return IVR
        if (isset($destination['ivr_menu'])) {
            return ExtensionType::IVR;
        }

        // If destination contains ai_assistant, return AI_ASSISTANT
        if (isset($destination['ai_assistant'])) {
            return ExtensionType::AI_ASSISTANT;
        }

        // If destination contains ai_load_balancer, return AI_LOAD_BALANCER
        if (isset($destination['ai_load_balancer'])) {
            return ExtensionType::AI_LOAD_BALANCER;
        }

        Log::warning('InboundRoutingService: Could not determine extension type', [
            'did_id' => $did->id,
            'destination_keys' => array_keys($destination),
        ]);

        return null;
    }

    /**
     * Create a CXML error response.
     *
     * @param  string  $message  The error message to speak
     * @return Response CXML response with error message and hangup
     */
    private function createErrorResponse(string $message): Response
    {
        return response(
            CxmlBuilder::sayWithHangup($message, true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
