<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Ring Group Routing Service
 *
 * Handles ring group callback routing for sequential ring group strategies.
 * Processes callbacks to determine the next member to try or fallback action.
 */
class RingGroupRoutingService
{
    public function __construct(
        private readonly VoiceRoutingStrategyExecutor $strategyExecutor
    ) {}

    /**
     * Handle ring group sequential callback routing.
     *
     * Processes callbacks from sequential ring group routing to determine
     * the next member to try or fallback action.
     *
     * @param  Request  $request  The incoming callback request
     * @return Response CXML response with next routing instruction
     */
    public function handleRingGroupCallback(Request $request): Response
    {
        $ringGroupId = (int) $request->input('ring_group_id');
        $callSid = $request->input('CallSid');
        $orgId = (int) $request->input('_organization_id');

        Log::info('RingGroupRoutingService: Handling ring group callback', [
            'call_sid' => $callSid,
            'ring_group_id' => $ringGroupId,
            'org_id' => $orgId,
        ]);

        // Validate required parameters
        if (! $ringGroupId || ! $callSid) {
            Log::warning('RingGroupRoutingService: Missing required parameters', [
                'call_sid' => $callSid,
                'ring_group_id' => $ringGroupId,
                'org_id' => $orgId,
            ]);

            return $this->createErrorResponse('Missing required parameters');
        }

        // Get the ring group
        $ringGroup = $this->findRingGroup($ringGroupId, $orgId);

        if (! $ringGroup) {
            Log::warning('RingGroupRoutingService: Ring group not found or inactive', [
                'call_sid' => $callSid,
                'ring_group_id' => $ringGroupId,
                'org_id' => $orgId,
            ]);

            return $this->createErrorResponse('Ring group not found');
        }

        Log::info('RingGroupRoutingService: Found active ring group for callback', [
            'call_sid' => $callSid,
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'strategy' => $ringGroup->strategy->value,
        ]);

        // Create destination array for the strategy
        $destination = ['ring_group' => $ringGroup];

        // Delegate to the RingGroupRoutingStrategy
        return $this->strategyExecutor->executeStrategy(
            ExtensionType::RING_GROUP,
            $request,
            new DidNumber,
            $destination
        );
    }

    /**
     * Find an active ring group by ID and organization.
     *
     * @param  int  $ringGroupId  The ring group ID
     * @param  int  $orgId  The organization ID
     * @return RingGroup|null The ring group if found and active, null otherwise
     */
    public function findRingGroup(int $ringGroupId, int $orgId): ?RingGroup
    {
        return RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $ringGroupId)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();
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
