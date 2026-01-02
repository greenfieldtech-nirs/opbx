<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
