<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class VoiceRoutingController extends Controller
{
    public function __construct(
        private readonly VoiceRoutingManager $manager
    ) {
    }

    /**
     * Handle inbound call routing.
     * Delegates to VoiceRoutingManager.
     */
    public function handleInbound(Request $request): Response
    {
        $orgId = $request->input('_organization_id');

        Log::info('VoiceRoutingController: Handling inbound request', [
            'to' => $request->input('To'),
            'from' => $request->input('From'),
            'domain' => $request->input('Domain'),
            'organization_id' => $orgId,
            'has_org_id' => $orgId !== null,
        ]);

        return $this->manager->handleInbound($request);
    }

    /**
     * Handle ring group callback.
     * Delegates to VoiceRoutingManager.
     */
    public function handleRingGroupCallback(Request $request): Response
    {
        return $this->manager->routeRingGroupCallback($request);
    }

    /**
     * Handle IVR input.
     * Delegates to VoiceRoutingManager.
     */
    public function handleIvrInput(Request $request): Response
    {
        return $this->manager->handleIvrInput($request);
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
