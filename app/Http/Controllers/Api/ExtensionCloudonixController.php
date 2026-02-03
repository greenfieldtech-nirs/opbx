<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Extension Cloudonix synchronization controller.
 *
 * Handles Cloudonix sync operations for extensions.
 */
class ExtensionCloudonixController extends Controller
{
    use ApiRequestHandler;

    public function __construct(
        protected CloudonixSubscriberService $cloudonixSubscriberService
    ) {
    }

    /**
     * Compare local extensions with Cloudonix subscribers.
     */
    public function compareSync(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('viewAny', Extension::class);

        Log::info('Comparing local extensions with Cloudonix subscribers', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        try {
            $result = $this->cloudonixSubscriberService->compareWithCloudonix($user->organization);

            // Log details if successful
            if (!isset($result['error'])) {
                Log::info('Comparison completed successfully', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'needs_sync' => $result['needs_sync'],
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Failed to compare extensions with Cloudonix', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to compare extensions with Cloudonix',
                'message' => 'An error occurred while comparing extensions.',
            ], 500);
        }
    }

    /**
     * Sync local extensions with Cloudonix.
     */
    public function performSync(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('create', Extension::class);

        Log::info('Syncing extensions with Cloudonix', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        try {
            // Use the service's bidirectional sync which handles both directions
            $result = $this->cloudonixSubscriberService->bidirectionalSync($user->organization);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Unknown error during sync');
            }

            return response()->json(array_merge(
                ['message' => 'Sync completed successfully.'],
                $result
            ));
        } catch (\Exception $e) {
            Log::error('Failed to sync extensions with Cloudonix', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to sync extensions with Cloudonix',
                'message' => 'An error occurred while syncing extensions.',
            ], 500);
        }
    }
}
