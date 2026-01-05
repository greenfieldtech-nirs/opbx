<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sentry\UpdateSentrySettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoutingSentryController extends Controller
{
    use ApiRequestHandler;
    public function getSettings(Request $request): JsonResponse
    {
        $organization = $this->getAuthenticatedUser($request)->organization;
        $settings = $organization->settings['routing_sentry'] ?? [
            'velocity_limit' => 10,
            'volume_limit' => 100,
            'default_action' => 'block',
        ];

        return response()->json(['data' => $settings]);
    }

    public function updateSettings(UpdateSentrySettingsRequest $request): JsonResponse
    {
        $organization = $this->getAuthenticatedUser($request)->organization;
        $settings = $organization->settings ?? [];
        $settings['routing_sentry'] = $request->validated();

        $organization->settings = $settings;
        $organization->save();

        return response()->json(['data' => $settings['routing_sentry']]);
    }
}
