<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Enums\AiAssistantStatus;
use App\Enums\AlbsStatus;
use App\Enums\CallTrackingDestinationType;
use App\Enums\ExtensionType;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHoursSchedule;
use App\Models\CallTrackingCampaign;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;

/**
 * Resolve a call tracking campaign destination into an OPBX routing destination.
 */
class CallTrackingDestinationResolver
{
    /**
     * Resolve the campaign destination.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(CallTrackingCampaign $campaign): ?array
    {
        return match ($campaign->destination_type) {
            CallTrackingDestinationType::FORWARD => $this->resolveForward($campaign),
            CallTrackingDestinationType::EXTENSION => $this->resolveExtension($campaign),
            CallTrackingDestinationType::RING_GROUP => $this->resolveRingGroup($campaign),
            CallTrackingDestinationType::BUSINESS_HOURS => $this->resolveBusinessHours($campaign),
            CallTrackingDestinationType::CONFERENCE_ROOM => $this->resolveConferenceRoom($campaign),
            CallTrackingDestinationType::IVR_MENU => $this->resolveIvrMenu($campaign),
            CallTrackingDestinationType::AI_ASSISTANT => $this->resolveAiAssistant($campaign),
            CallTrackingDestinationType::AI_LOAD_BALANCER => $this->resolveAiLoadBalancer($campaign),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveForward(CallTrackingCampaign $campaign): array
    {
        return [
            'type' => ExtensionType::FORWARD,
            'forward_to' => $campaign->getForwardTo(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveExtension(CallTrackingCampaign $campaign): ?array
    {
        $extensionId = $campaign->getDestinationId('extension_id');

        if (! $extensionId) {
            return null;
        }

        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $extensionId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $extension) {
            return null;
        }

        return [
            'type' => ExtensionType::USER,
            'extension' => $extension,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRingGroup(CallTrackingCampaign $campaign): ?array
    {
        $ringGroupId = $campaign->getDestinationId('ring_group_id');

        if (! $ringGroupId) {
            return null;
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $ringGroup) {
            return null;
        }

        return [
            'type' => ExtensionType::RING_GROUP,
            'ring_group' => $ringGroup,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveConferenceRoom(CallTrackingCampaign $campaign): ?array
    {
        $conferenceRoomId = $campaign->getDestinationId('conference_room_id');

        if (! $conferenceRoomId) {
            return null;
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $conferenceRoom) {
            return null;
        }

        return [
            'type' => ExtensionType::CONFERENCE,
            'conference_room' => $conferenceRoom,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveIvrMenu(CallTrackingCampaign $campaign): ?array
    {
        $ivrMenuId = $campaign->getDestinationId('ivr_menu_id');

        if (! $ivrMenuId) {
            return null;
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $ivrMenu) {
            return null;
        }

        return [
            'type' => ExtensionType::IVR,
            'ivr_menu' => $ivrMenu,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAiAssistant(CallTrackingCampaign $campaign): ?array
    {
        $aiAssistantId = $campaign->getDestinationId('ai_assistant_id');

        if (! $aiAssistantId) {
            return null;
        }

        $aiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $aiAssistantId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', AiAssistantStatus::ACTIVE)
            ->first();

        if (! $aiAssistant) {
            return null;
        }

        return [
            'type' => ExtensionType::AI_ASSISTANT,
            'ai_assistant' => $aiAssistant,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAiLoadBalancer(CallTrackingCampaign $campaign): ?array
    {
        $loadBalancerId = $campaign->getDestinationId('ai_load_balancer_id');

        if (! $loadBalancerId) {
            return null;
        }

        $loadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $loadBalancerId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', AlbsStatus::ACTIVE)
            ->first();

        if (! $loadBalancer) {
            return null;
        }

        return [
            'type' => ExtensionType::AI_LOAD_BALANCER,
            'ai_load_balancer' => $loadBalancer,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHours(CallTrackingCampaign $campaign): ?array
    {
        $scheduleId = $campaign->getDestinationId('business_hours_schedule_id');

        if (! $scheduleId) {
            return null;
        }

        $schedule = BusinessHoursSchedule::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $scheduleId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $schedule) {
            return null;
        }

        $actionType = $schedule->getCurrentRoutingType();
        $targetId = $schedule->getCurrentRoutingTargetId();

        return $this->resolveBusinessHoursTarget($campaign, $schedule, $actionType->value, $targetId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursTarget(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $actionType,
        ?string $targetId
    ): ?array {
        if (! $targetId) {
            return [
                'type' => null,
                'business_hours' => $schedule,
            ];
        }

        return match ($actionType) {
            'extension' => $this->resolveBusinessHoursExtension($campaign, $schedule, $targetId),
            'ring_group' => $this->resolveBusinessHoursRingGroup($campaign, $schedule, $targetId),
            'conference_room' => $this->resolveBusinessHoursConferenceRoom($campaign, $schedule, $targetId),
            'ivr_menu' => $this->resolveBusinessHoursIvrMenu($campaign, $schedule, $targetId),
            'ai_assistant' => $this->resolveBusinessHoursAiAssistant($campaign, $schedule, $targetId),
            'ai_load_balancer' => $this->resolveBusinessHoursAiLoadBalancer($campaign, $schedule, $targetId),
            default => [
                'type' => null,
                'business_hours' => $schedule,
            ],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursExtension(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $extensionId = $this->parsePrefixedId($targetId, 'ext');

        if (! $extensionId) {
            return null;
        }

        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $extensionId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $extension) {
            return null;
        }

        return [
            'type' => ExtensionType::USER,
            'extension' => $extension,
            'business_hours' => $schedule,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursRingGroup(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $ringGroupId = $this->parsePrefixedId($targetId, 'rg');

        if (! $ringGroupId) {
            return null;
        }

        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $ringGroup) {
            return null;
        }

        return [
            'type' => ExtensionType::RING_GROUP,
            'ring_group' => $ringGroup,
            'business_hours' => $schedule,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursConferenceRoom(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $conferenceRoomId = $this->parsePrefixedId($targetId, 'conf');

        if (! $conferenceRoomId) {
            return null;
        }

        $conferenceRoom = ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $conferenceRoomId)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $conferenceRoom) {
            return null;
        }

        return [
            'type' => ExtensionType::CONFERENCE,
            'conference_room' => $conferenceRoom,
            'business_hours' => $schedule,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursIvrMenu(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $ivrMenuId = $this->parsePrefixedId($targetId, 'ivr');

        if (! $ivrMenuId) {
            return null;
        }

        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ivrMenuId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $ivrMenu) {
            return null;
        }

        return [
            'type' => ExtensionType::IVR,
            'ivr_menu' => $ivrMenu,
            'business_hours' => $schedule,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursAiAssistant(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $aiAssistantId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^ai-(\d+)$/', $targetId, $matches)) {
            $aiAssistantId = (int) $matches[1];
        }

        if (! $aiAssistantId) {
            return null;
        }

        $aiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $aiAssistantId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', AiAssistantStatus::ACTIVE)
            ->first();

        if (! $aiAssistant) {
            return null;
        }

        return [
            'type' => ExtensionType::AI_ASSISTANT,
            'ai_assistant' => $aiAssistant,
            'business_hours' => $schedule,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBusinessHoursAiLoadBalancer(
        CallTrackingCampaign $campaign,
        BusinessHoursSchedule $schedule,
        string $targetId
    ): ?array {
        $loadBalancerId = is_numeric($targetId) ? (int) $targetId : null;
        if (preg_match('/^alb[s]?-(\d+)$/', $targetId, $matches)) {
            $loadBalancerId = (int) $matches[1];
        }

        if (! $loadBalancerId) {
            return null;
        }

        $loadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $loadBalancerId)
            ->where('organization_id', $campaign->organization_id)
            ->where('status', AlbsStatus::ACTIVE)
            ->first();

        if (! $loadBalancer) {
            return null;
        }

        return [
            'type' => ExtensionType::AI_LOAD_BALANCER,
            'ai_load_balancer' => $loadBalancer,
            'business_hours' => $schedule,
        ];
    }

    /**
     * Parse a prefixed target ID.
     */
    private function parsePrefixedId(string $targetId, string $prefix): ?int
    {
        if (is_numeric($targetId)) {
            return (int) $targetId;
        }

        if (preg_match('/^'.$prefix.'-(\d+)$/', $targetId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
