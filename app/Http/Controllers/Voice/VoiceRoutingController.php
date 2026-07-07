<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\IvrInputRequest;
use App\Http\Requests\Voice\RingGroupCallbackRequest;
use App\Http\Requests\Voice\VoiceRoutingRequest;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VoiceRoutingController extends Controller
{
    public function __construct(
        private readonly VoiceRoutingManager $manager
    ) {}

    /**
     * Get or generate a unique request ID for logging.
     */
    protected function getRequestId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Handle inbound call routing.
     * Delegates to VoiceRoutingManager.
     */
    public function handleInbound(VoiceRoutingRequest $request): Response
    {
        $orgId = $request->input('_organization_id');

        Log::info('VoiceRoutingController: Handling inbound request', [
            'to' => $request->input('To'),
            'from' => $request->input('From'),
            'domain' => $request->input('Domain'),
            'organization_id' => $orgId,
            'has_org_id' => $orgId !== null,
        ]);

        return OrganizationScope::bypass(fn () => $this->manager->handleInbound($request));
    }

    /**
     * Handle ring group callback.
     * Delegates to VoiceRoutingManager.
     */
    public function handleRingGroupCallback(RingGroupCallbackRequest $request): Response
    {
        $requestId = $this->getRequestId();

        Log::info('VoiceRoutingController: Ring group callback received', [
            'request_id' => $requestId,
            'ring_group_id' => $request->input('ring_group_id'),
            'attempt_number' => $request->input('attempt_number'),
            'call_sid' => $request->input('CallSid'),
            'organization_id' => $request->input('_organization_id'),
        ]);

        $response = OrganizationScope::bypass(fn () => $this->manager->routeRingGroupCallback($request));

        Log::info('VoiceRoutingController: Ring group callback response generated', [
            'request_id' => $requestId,
            'response_content_length' => strlen($response->getContent()),
        ]);

        return $response;
    }

    /**
     * Handle IVR input.
     * Delegates to VoiceRoutingManager.
     */
    public function handleIvrInput(IvrInputRequest $request): Response
    {
        return OrganizationScope::bypass(fn () => $this->manager->handleIvrInput($request));
    }

    /**
     * Health check endpoint.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'voice-routing',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
