<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\StoreAdPlatformIntegrationRequest;
use App\Http\Resources\CallTrackingAdPlatformIntegrationResource;
use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallTrackingAdPlatformIntegrationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $integration = CallTrackingAdPlatformIntegration::forOrganization((int) $user->organization_id)->first();

        if (! $integration) {
            return response()->json([
                'data' => [
                    'organization_id' => (int) $user->organization_id,
                    'google_ads' => ['enabled' => false, 'is_configured' => false],
                    'meta' => ['enabled' => false, 'is_configured' => false],
                    'updated_at' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => new CallTrackingAdPlatformIntegrationResource($integration),
        ]);
    }

    public function update(StoreAdPlatformIntegrationRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $payload = [
            'organization_id' => (int) $user->organization_id,
            'google_ads_enabled' => $validated['google_ads_enabled'],
            'meta_enabled' => $validated['meta_enabled'],
        ];

        foreach (
            [
                'google_ads_developer_token',
                'google_ads_refresh_token',
                'google_ads_customer_id',
                'google_ads_conversion_action_resource_name',
            ] as $field
        ) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $payload[$field] = $validated[$field];
            }
        }

        foreach (['meta_pixel_id', 'meta_access_token'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $payload[$field] = $validated[$field];
            }
        }

        $integration = CallTrackingAdPlatformIntegration::updateOrCreate(
            ['organization_id' => (int) $user->organization_id],
            $payload
        );

        Log::info('Call tracking ad-platform integration settings updated', [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'message' => 'Integration settings updated successfully.',
            'data' => new CallTrackingAdPlatformIntegrationResource($integration),
        ]);
    }
}
