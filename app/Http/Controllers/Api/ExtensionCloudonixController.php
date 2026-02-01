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
    ) {}

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
            $extensions = Extension::forOrganization($user->organization_id)->get();
            $subscriberCounts = [];
            $localCounts = [];

            foreach ($extensions as $extension) {
                if ($extension->cloudonix_identity) {
                    $subscriberCounts[$extension->cloudonix_identity] = true;
                }
                $localCounts[$extension->extension_number] = true;
            }

            $missingInCloudonix = [];
            foreach ($extensions as $extension) {
                if ($extension->type->value === 'user' && ! $extension->cloudonix_identity) {
                    $missingInCloudonix[] = $extension->extension_number;
                }
            }

            $result = [
                'total_local_extensions' => count($extensions),
                'extensions_in_cloudonix' => count($subscriberCounts),
                'missing_in_cloudonix' => $missingInCloudonix,
                'local_extension_numbers' => array_keys($localCounts),
            ];

            Log::info('Comparison completed successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'total_local' => $result['total_local_extensions'],
                'in_cloudonix' => $result['extensions_in_cloudonix'],
            ]);

            return response()->json(['data' => $result]);
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
        $this->authorize('update', Extension::class);

        $scope = $request->input('scope', 'all');

        Log::info('Syncing extensions with Cloudonix', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'scope' => $scope,
        ]);

        try {
            $extensions = Extension::forOrganization($user->organization_id);

            if ($scope === 'missing') {
                $extensions = $extensions->whereNull('cloudonix_identity');
            }

            $extensions = $extensions->get();
            $synced = 0;
            $failed = 0;
            $errors = [];

            foreach ($extensions as $extension) {
                if ($extension->type->value !== 'user') {
                    continue;
                }

                try {
                    $subscriber = $this->cloudonixSubscriberService->createOrUpdateSubscriber(
                        $user->organization,
                        $extension
                    );

                    if ($subscriber && ! empty($subscriber['identity'])) {
                        $extension->update(['cloudonix_identity' => $subscriber['identity']]);
                        $synced++;
                    } else {
                        $failed++;
                        $errors[] = [
                            'extension' => $extension->extension_number,
                            'reason' => 'No subscriber identity returned',
                        ];
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'extension' => $extension->extension_number,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            Log::info('Sync completed', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'synced' => $synced,
                'failed' => $failed,
            ]);

            return response()->json([
                'message' => 'Sync completed successfully.',
                'data' => [
                    'synced' => $synced,
                    'failed' => $failed,
                    'errors' => $errors,
                ],
            ]);
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
