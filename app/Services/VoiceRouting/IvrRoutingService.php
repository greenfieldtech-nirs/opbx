<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\BusinessHoursActionType;
use App\Enums\ExtensionType;
use App\Enums\IvrDestinationType;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\IvrStateService;
use App\Services\VoiceRouting\Strategies\IvrRoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * IVR Routing Service
 *
 * Handles IVR menu processing, option handling, and state management.
 * Manages DTMF input processing and routing to IVR destinations.
 */
class IvrRoutingService
{
    public function __construct(
        private readonly IvrStateService $ivrStateService,
        private readonly ExtensionRoutingService $extensionRoutingService,
        private readonly VoiceRoutingStrategyExecutor $strategyExecutor
    ) {}

    /**
     * Handle IVR DTMF input from caller.
     *
     * @param  Request  $request  The incoming webhook request
     * @return Response CXML response with next routing instruction
     */
    public function handleIvrInput(Request $request): Response
    {
        try {
            $callSid = $request->input('CallSid');
            $digits = $request->input('Digits', '');
            $menuId = (int) $request->query('menu_id');
            $orgId = (int) $request->input('_organization_id');

            Log::debug('IvrRoutingService: Processing DTMF input', [
                'call_sid' => $callSid,
                'digits' => $digits,
                'menu_id' => $menuId,
                'org_id' => $orgId,
            ]);

            // Validate menu exists and belongs to organization
            $ivrMenu = $this->findIvrMenu($menuId, $orgId);

            if (! $ivrMenu) {
                Log::warning('IvrRoutingService: Menu not found or inactive', [
                    'call_sid' => $callSid,
                    'menu_id' => $menuId,
                    'org_id' => $orgId,
                ]);

                return $this->createErrorResponse('Menu configuration error.');
            }

            Log::debug('IvrRoutingService: Menu found and active', [
                'call_sid' => $callSid,
                'menu_id' => $menuId,
                'menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
            ]);

            // Get current call state
            $callState = $this->ivrStateService->getCallState($callSid);

            if (! $callState) {
                Log::warning('IvrRoutingService: No call state found', [
                    'call_sid' => $callSid,
                    'menu_id' => $menuId,
                ]);

                return $this->createErrorResponse('Call state error. Please try again.');
            }

            // Handle no input (timeout)
            if (empty($digits)) {
                return $this->handleNoInput($request, $ivrMenu, $callState);
            }

            // Find matching option
            $option = $ivrMenu->findOptionByDigits($digits);

            Log::debug('IvrRoutingService: Option lookup result', [
                'call_sid' => $callSid,
                'digits' => $digits,
                'option_found' => $option !== null,
                'option_id' => $option?->id,
            ]);

            if ($option) {
                return $this->handleValidOption($request, $ivrMenu, $option);
            }

            return $this->handleInvalidOption($request, $ivrMenu, $callState, $digits);
        } catch (\Exception $e) {
            Log::error('IvrRoutingService: Unexpected exception', [
                'call_sid' => $request->input('CallSid'),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->createErrorResponse('An unexpected error occurred.');
        }
    }

    /**
     * Find an active IVR menu by ID and organization.
     *
     * @param  int  $menuId  The IVR menu ID
     * @param  int  $orgId  The organization ID
     * @return IvrMenu|null The IVR menu if found and active, null otherwise
     */
    public function findIvrMenu(int $menuId, int $orgId): ?IvrMenu
    {
        return IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $menuId)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Handle case where caller provides no input (timeout).
     *
     * @param  Request  $request  The incoming request
     * @param  IvrMenu  $ivrMenu  The current IVR menu
     * @param  array<string, mixed>  $callState  The current call state
     * @return Response CXML response
     */
    public function handleNoInput(Request $request, IvrMenu $ivrMenu, array $callState): Response
    {
        $callSid = $request->input('CallSid');
        $turnCount = $this->ivrStateService->incrementTurnCount($callSid);

        Log::info('IvrRoutingService: No input provided (timeout)', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'turn_count' => $turnCount,
            'max_turns' => $ivrMenu->max_turns,
        ]);

        if ($this->ivrStateService->isMaxTurnsExceeded($callSid, $ivrMenu->max_turns)) {
            return $this->routeToFailoverDestination($request, $ivrMenu);
        }

        // Replay menu
        $destination = ['ivr_menu' => $ivrMenu];

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::IVR,
            $request,
            new DidNumber,
            $destination
        );
    }

    /**
     * Handle valid option selection.
     *
     * @param  Request  $request  The incoming request
     * @param  IvrMenu  $ivrMenu  The current IVR menu
     * @param  mixed  $option  The selected option
     * @return Response CXML response
     */
    public function handleValidOption(Request $request, IvrMenu $ivrMenu, $option): Response
    {
        $callSid = $request->input('CallSid');
        $digits = $request->input('Digits');

        Log::info('IvrRoutingService: Valid option selected', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'digits' => $digits,
            'destination_type' => $option->destination_type->value,
            'destination_id' => $option->destination_id,
        ]);

        return $this->routeToOptionDestination($request, $option, $ivrMenu);
    }

    /**
     * Handle invalid option selection.
     *
     * @param  Request  $request  The incoming request
     * @param  IvrMenu  $ivrMenu  The current IVR menu
     * @param  array<string, mixed>  $callState  The current call state
     * @param  string  $digits  The invalid digits entered
     * @return Response CXML response
     */
    public function handleInvalidOption(
        Request $request,
        IvrMenu $ivrMenu,
        array $callState,
        string $digits
    ): Response {
        $callSid = $request->input('CallSid');
        $turnCount = $this->ivrStateService->incrementTurnCount($callSid);

        Log::info('IvrRoutingService: Invalid option selected', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'digits' => $digits,
            'turn_count' => $turnCount,
            'max_turns' => $ivrMenu->max_turns,
        ]);

        if ($this->ivrStateService->isMaxTurnsExceeded($callSid, $ivrMenu->max_turns)) {
            return $this->routeToFailoverDestination($request, $ivrMenu);
        }

        // Play error message and replay menu
        $errorMessage = 'Invalid menu option, please try again.';
        $destination = ['ivr_menu' => $ivrMenu];
        $ivrStrategy = new IvrRoutingStrategy($this->ivrStateService);

        return $ivrStrategy->route($request, new DidNumber, $destination, $errorMessage);
    }

    /**
     * Route call to option destination.
     *
     * @param  Request  $request  The incoming request
     * @param  mixed  $option  The selected option
     * @param  IvrMenu  $ivrMenu  The current IVR menu
     * @return Response CXML response
     */
    public function routeToOptionDestination(Request $request, $option, IvrMenu $ivrMenu): Response
    {
        Log::debug('IvrRoutingService: Routing to option destination', [
            'call_sid' => $request->input('CallSid'),
            'option_id' => $option->id,
            'destination_type' => $option->destination_type->value,
        ]);

        try {
            $validatedDestination = $option->getValidatedDestination($ivrMenu);

            if (! $validatedDestination) {
                Log::error('IvrRoutingService: Destination validation failed', [
                    'call_sid' => $request->input('CallSid'),
                    'option_id' => $option->id,
                ]);

                return $this->createErrorResponse('Destination is no longer available.');
            }

            return match ($option->destination_type) {
                IvrDestinationType::EXTENSION => $this->routeToExtension($request, $validatedDestination),
                IvrDestinationType::RING_GROUP => $this->routeToRingGroup($request, $validatedDestination),
                IvrDestinationType::CONFERENCE_ROOM => $this->routeToConferenceRoom($request, $validatedDestination),
                IvrDestinationType::IVR_MENU => $this->routeToIvrMenu($request, $validatedDestination),
                IvrDestinationType::AI_ASSISTANT => $this->routeToAiAssistant($request, $validatedDestination),
                IvrDestinationType::AI_LOAD_BALANCER => $this->routeToAiLoadBalancer($request, $validatedDestination),
                IvrDestinationType::BUSINESS_HOURS => $this->routeToBusinessHours($request, $validatedDestination),
                default => $this->createErrorResponse('Unknown destination type.'),
            };
        } catch (\Exception $e) {
            Log::error('IvrRoutingService: Exception in destination routing', [
                'call_sid' => $request->input('CallSid'),
                'exception' => $e->getMessage(),
            ]);

            return $this->createErrorResponse('Routing error occurred.');
        }
    }

    /**
     * Route to extension destination.
     *
     * @param  Request  $request  The incoming request
     * @param  Extension  $extension  The validated extension
     * @return Response CXML response
     */
    private function routeToExtension(Request $request, Extension $extension): Response
    {
        Log::debug('IvrRoutingService: Routing to extension', [
            'call_sid' => $request->input('CallSid'),
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        $destination = $this->extensionRoutingService->resolveExtensionDestination(
            $extension,
            $extension->organization_id
        );

        return $this->strategyExecutor->executeStrategy(
            $extension->type,
            $request,
            new DidNumber,
            $destination
        );
    }

    /**
     * Route to ring group destination.
     *
     * @param  Request  $request  The incoming request
     * @param  RingGroup  $ringGroup  The validated ring group
     * @return Response CXML response
     */
    private function routeToRingGroup(Request $request, RingGroup $ringGroup): Response
    {
        Log::debug('IvrRoutingService: Routing to ring group', [
            'call_sid' => $request->input('CallSid'),
            'ring_group_id' => $ringGroup->id,
        ]);

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::RING_GROUP,
            $request,
            new DidNumber,
            ['ring_group' => $ringGroup]
        );
    }

    /**
     * Route to conference room destination.
     *
     * @param  Request  $request  The incoming request
     * @param  ConferenceRoom  $conferenceRoom  The validated conference room
     * @return Response CXML response
     */
    private function routeToConferenceRoom(Request $request, ConferenceRoom $conferenceRoom): Response
    {
        Log::debug('IvrRoutingService: Routing to conference room', [
            'call_sid' => $request->input('CallSid'),
            'conference_room_id' => $conferenceRoom->id,
        ]);

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::CONFERENCE,
            $request,
            new DidNumber,
            ['conference_room' => $conferenceRoom]
        );
    }

    /**
     * Route to IVR menu destination.
     *
     * @param  Request  $request  The incoming request
     * @param  IvrMenu  $targetIvrMenu  The validated IVR menu
     * @return Response CXML response
     */
    private function routeToIvrMenu(Request $request, IvrMenu $targetIvrMenu): Response
    {
        Log::debug('IvrRoutingService: Routing to IVR menu', [
            'call_sid' => $request->input('CallSid'),
            'ivr_menu_id' => $targetIvrMenu->id,
        ]);

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::IVR,
            $request,
            new DidNumber,
            ['ivr_menu' => $targetIvrMenu]
        );
    }

    /**
     * Route to AI Assistant destination.
     *
     * @param  Request  $request  The incoming request
     * @param  mixed  $aiAssistant  The validated AI assistant
     * @return Response CXML response
     */
    private function routeToAiAssistant(Request $request, $aiAssistant): Response
    {
        Log::debug('IvrRoutingService: Routing to AI Assistant', [
            'call_sid' => $request->input('CallSid'),
            'ai_assistant_id' => $aiAssistant->id,
        ]);

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::AI_ASSISTANT,
            $request,
            new DidNumber,
            ['ai_assistant' => $aiAssistant]
        );
    }

    /**
     * Route to AI Load Balancer destination.
     *
     * @param  Request  $request  The incoming request
     * @param  mixed  $aiLoadBalancer  The validated AI load balancer
     * @return Response CXML response
     */
    private function routeToAiLoadBalancer(Request $request, $aiLoadBalancer): Response
    {
        Log::debug('IvrRoutingService: Routing to AI Load Balancer', [
            'call_sid' => $request->input('CallSid'),
            'ai_load_balancer_id' => $aiLoadBalancer->id,
        ]);

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::AI_LOAD_BALANCER,
            $request,
            new DidNumber,
            ['ai_load_balancer' => $aiLoadBalancer]
        );
    }

    /**
     * Route to business hours destination.
     *
     * @param  Request  $request  The incoming request
     * @param  mixed  $businessHoursSchedule  The validated business hours schedule
     * @return Response CXML response
     */
    private function routeToBusinessHours(Request $request, $businessHoursSchedule): Response
    {
        Log::debug('IvrRoutingService: Routing to Business Hours', [
            'call_sid' => $request->input('CallSid'),
            'business_hours_id' => $businessHoursSchedule->id,
        ]);

        $actionType = $businessHoursSchedule->getCurrentRoutingType();
        $targetId = $businessHoursSchedule->getCurrentRoutingTargetId();

        return $this->routeBusinessHoursAction($request, $actionType, $targetId, $businessHoursSchedule->organization_id);
    }

    /**
     * Route based on business hours action type.
     *
     * @param  Request  $request  The incoming request
     * @param  BusinessHoursActionType  $actionType  The action type
     * @param  string|null  $targetId  The target ID
     * @param  int  $organizationId  The organization ID
     * @return Response CXML response
     */
    public function routeBusinessHoursAction(
        Request $request,
        BusinessHoursActionType $actionType,
        ?string $targetId,
        int $organizationId
    ): Response {
        $callSid = $request->input('CallSid');

        Log::debug('IvrRoutingService: Routing business hours action', [
            'call_sid' => $callSid,
            'action_type' => $actionType->value,
            'target_id' => $targetId,
        ]);

        return match ($actionType) {
            BusinessHoursActionType::EXTENSION => $this->routeBusinessHoursToExtension($request, $targetId, $organizationId),
            BusinessHoursActionType::RING_GROUP => $this->routeBusinessHoursToRingGroup($request, $targetId, $organizationId),
            BusinessHoursActionType::CONFERENCE_ROOM => $this->routeBusinessHoursToConferenceRoom($request, $targetId, $organizationId),
            BusinessHoursActionType::IVR_MENU => $this->routeBusinessHoursToIvrMenu($request, $targetId, $organizationId),
            BusinessHoursActionType::AI_ASSISTANT => $this->routeBusinessHoursToAiAssistant($request, $targetId, $organizationId),
            BusinessHoursActionType::AI_LOAD_BALANCER => $this->routeBusinessHoursToAiLoadBalancer($request, $targetId, $organizationId),
            default => $this->createErrorResponse('Business hours routing not available.'),
        };
    }

    /**
     * Route business hours action to extension.
     */
    private function routeBusinessHoursToExtension(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $extensionId = $this->parseTargetId($targetId, 'ext');

        if (! $extensionId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $extensionId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (! $extension) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $destination = $this->extensionRoutingService->resolveExtensionDestination($extension, $organizationId);

        return $this->strategyExecutor->executeStrategy($extension->type, $request, new DidNumber, $destination);
    }

    /**
     * Route business hours action to ring group.
     */
    private function routeBusinessHoursToRingGroup(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $ringGroupId = $this->parseTargetId($targetId, 'rg');

        if (! $ringGroupId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (! $ringGroup) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::RING_GROUP,
            $request,
            new DidNumber,
            ['ring_group' => $ringGroup]
        );
    }

    /**
     * Route business hours action to conference room.
     */
    private function routeBusinessHoursToConferenceRoom(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $conferenceRoomId = $this->parseTargetId($targetId, 'conf');

        if (! $conferenceRoomId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $conferenceRoom) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::CONFERENCE,
            $request,
            new DidNumber,
            ['conference_room' => $conferenceRoom]
        );
    }

    /**
     * Route business hours action to IVR menu.
     */
    private function routeBusinessHoursToIvrMenu(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $ivrMenuId = $this->parseTargetId($targetId, 'ivr');

        if (! $ivrMenuId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (! $ivrMenu) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::IVR,
            $request,
            new DidNumber,
            ['ivr_menu' => $ivrMenu]
        );
    }

    /**
     * Route business hours action to AI assistant.
     */
    private function routeBusinessHoursToAiAssistant(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $aiAssistantId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^ai-(\d+)$/', $targetId, $matches)) {
            $aiAssistantId = (int) $matches[1];
        }

        if (! $aiAssistantId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $aiAssistant = \App\Models\AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $aiAssistantId)
            ->where('organization_id', $organizationId)
            ->where('status', \App\Enums\AiAssistantStatus::ACTIVE)
            ->first();

        if (! $aiAssistant) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::AI_ASSISTANT,
            $request,
            new DidNumber,
            ['ai_assistant' => $aiAssistant]
        );
    }

    /**
     * Route business hours action to AI load balancer.
     */
    private function routeBusinessHoursToAiLoadBalancer(
        Request $request,
        ?string $targetId,
        int $organizationId
    ): Response {
        if (! $targetId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $albsId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^alb[s]?-(\d+)$/', $targetId, $matches)) {
            $albsId = (int) $matches[1];
        }

        if (! $albsId) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        $aiLoadBalancer = \App\Models\AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $albsId)
            ->where('organization_id', $organizationId)
            ->where('status', \App\Enums\AlbsStatus::ACTIVE)
            ->first();

        if (! $aiLoadBalancer) {
            return $this->createErrorResponse('Business hours routing not available.');
        }

        return $this->strategyExecutor->executeStrategy(
            ExtensionType::AI_LOAD_BALANCER,
            $request,
            new DidNumber,
            ['ai_load_balancer' => $aiLoadBalancer]
        );
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
     * Route call to IVR menu failover destination.
     *
     * @param  Request  $request  The incoming request
     * @param  IvrMenu  $ivrMenu  The current IVR menu
     * @return Response CXML response
     */
    public function routeToFailoverDestination(Request $request, IvrMenu $ivrMenu): Response
    {
        $callSid = $request->input('CallSid');

        Log::info('IvrRoutingService: Routing to failover destination', [
            'call_sid' => $callSid,
            'menu_id' => $ivrMenu->id,
            'failover_type' => $ivrMenu->failover_destination_type->value,
        ]);

        if ($ivrMenu->failover_destination_type === IvrDestinationType::HANGUP) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        return $this->routeToDestination(
            $request,
            $ivrMenu->failover_destination_type,
            $ivrMenu->failover_destination_id,
            $ivrMenu
        );
    }

    /**
     * Route to a specific destination type and ID.
     *
     * @param  Request  $request  The incoming request
     * @param  IvrDestinationType  $destinationType  The destination type
     * @param  int  $destinationId  The destination ID
     * @param  IvrMenu  $ivrMenu  The current IVR menu (for organization context)
     * @return Response CXML response
     */
    public function routeToDestination(
        Request $request,
        IvrDestinationType $destinationType,
        int $destinationId,
        IvrMenu $ivrMenu
    ): Response {
        Log::info('IvrRoutingService: Routing to destination', [
            'call_sid' => $request->input('CallSid'),
            'destination_type' => $destinationType->value,
            'destination_id' => $destinationId,
        ]);

        $validatedDestination = $this->resolveDestination($destinationType, $destinationId, $ivrMenu->organization_id);

        if (! $validatedDestination) {
            Log::error('IvrRoutingService: Destination validation failed', [
                'call_sid' => $request->input('CallSid'),
                'destination_type' => $destinationType->value,
                'destination_id' => $destinationId,
            ]);

            return $this->createErrorResponse('Destination is no longer available.');
        }

        return match ($destinationType) {
            IvrDestinationType::EXTENSION => $this->routeToExtension($request, $validatedDestination),
            IvrDestinationType::RING_GROUP => $this->routeToRingGroup($request, $validatedDestination),
            IvrDestinationType::CONFERENCE_ROOM => $this->routeToConferenceRoom($request, $validatedDestination),
            IvrDestinationType::IVR_MENU => $this->routeToIvrMenu($request, $validatedDestination),
            IvrDestinationType::AI_ASSISTANT => $this->routeToAiAssistant($request, $validatedDestination),
            IvrDestinationType::AI_LOAD_BALANCER => $this->routeToAiLoadBalancer($request, $validatedDestination),
            default => $this->createErrorResponse('Unknown destination type.'),
        };
    }

    /**
     * Resolve destination based on type and ID.
     *
     * @param  IvrDestinationType  $destinationType  The destination type
     * @param  int  $destinationId  The destination ID
     * @param  int  $organizationId  The organization ID
     * @return mixed The resolved destination model or null
     */
    private function resolveDestination(
        IvrDestinationType $destinationType,
        int $destinationId,
        int $organizationId
    ): mixed {
        return match ($destinationType) {
            IvrDestinationType::EXTENSION => Extension::withoutGlobalScope(OrganizationScope::class)
                ->where('extension_number', (string) $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            IvrDestinationType::RING_GROUP => RingGroup::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            IvrDestinationType::CONFERENCE_ROOM => ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            IvrDestinationType::IVR_MENU => IvrMenu::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            IvrDestinationType::AI_ASSISTANT => \App\Models\AiAssistant::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            IvrDestinationType::AI_LOAD_BALANCER => \App\Models\AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $destinationId)
                ->where('organization_id', $organizationId)
                ->first(),
            default => null,
        };
    }

    /**
     * Create a CXML error response.
     *
     * @param  string  $message  The error message
     * @return Response CXML response
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
