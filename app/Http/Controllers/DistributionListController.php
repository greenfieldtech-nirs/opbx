<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ListStatus;
use App\Http\Resources\DistributionListResource;
use App\Http\Resources\ListDestinationResource;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Services\AutoDialer\DestinationManagementService;
use App\Services\AutoDialer\ListManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DistributionListController extends Controller
{
    public function __construct(
        private ListManagementService $listService,
        private DestinationManagementService $destinationService,
    ) {}

    /**
     * List all distribution lists for the organization.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerList::class);

        $lists = AutoDialerList::where('organization_id', Auth::user()->organization_id)
            ->where('is_latest_version', true) // Only show latest versions
            ->with(['campaign', 'usedByCampaign'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->campaign_id, fn ($q) => $q->where('campaign_id', $request->campaign_id))
            ->when($request->search, function ($q, $search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'data' => DistributionListResource::collection($lists),
            'meta' => [
                'current_page' => $lists->currentPage(),
                'last_page' => $lists->lastPage(),
                'per_page' => $lists->perPage(),
                'total' => $lists->total(),
            ],
        ]);
    }

    /**
     * Create a new empty list.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AutoDialerList::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $list = $this->listService->createList(
            Auth::user()->organization_id,
            $validated['name'],
            $validated['description'] ?? null,
        );

        return response()->json([
            'message' => 'List created successfully',
            'data' => new DistributionListResource($list),
        ], 201);
    }

    /**
     * Get a single list.
     */
    public function show(AutoDialerList $list): JsonResponse
    {
        $this->authorize('view', $list);

        $list->load(['campaign', 'usedByCampaign', 'parentList', 'versions']);

        return response()->json([
            'data' => new DistributionListResource($list),
        ]);
    }

    /**
     * Upload CSV file to populate list (unified endpoint).
     *
     * If list is in DRAFT/PENDING/FAILED: uploads to current list.
     * If list is in READY/USED: backs up old destinations, updates same list.
     */
    public function upload(Request $request, AutoDialerList $list): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ]);

        $file = $validated['file'];

        // Determine action based on list status
        $canUploadToCurrent = $list->status->canUpload();
        $canUpdateList = $list->status->canCreateVersion(); // READY/USED can be updated

        if (! $canUploadToCurrent && ! $canUpdateList) {
            return response()->json([
                'error' => 'Cannot upload to list in status: '.$list->status->label(),
            ], 422);
        }

        try {
            if ($canUploadToCurrent) {
                // Upload to current list
                $this->authorize('upload', $list);

                $result = $this->listService->uploadCsv($list->id, $file);

                return response()->json([
                    'message' => 'File uploaded successfully. Processing started.',
                    'data' => [
                        ...$result,
                        'action' => 'upload',
                        'list_id' => $list->id,
                    ],
                ]);
            } else {
                // Update existing list - backup old destinations first
                $this->authorize('createVersion', $list);

                // Backup old destinations and update same list
                $result = $this->listService->updateListWithBackup($list->id, $file);

                return response()->json([
                    'message' => 'List updated with new data. Old destinations backed up. Processing started.',
                    'data' => [
                        ...$result,
                        'action' => 'update',
                        'list_id' => $list->id,
                        'new_version_number' => $result['version_number'],
                    ],
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to process upload: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upload progress.
     */
    public function uploadProgress(string $jobId): JsonResponse
    {
        $progress = $this->listService->getUploadProgress($jobId);

        if (! $progress) {
            return response()->json([
                'error' => 'Job not found or expired',
            ], 404);
        }

        return response()->json([
            'data' => $progress,
        ]);
    }

    /**
     * Add single destination.
     */
    public function addDestination(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('upload', $list);

        $validated = $request->validate([
            'phone_number' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $destination = $this->listService->addDestination(
                $list->id,
                $validated['phone_number'],
                $validated['description'] ?? null,
            );

            return response()->json([
                'message' => 'Destination added successfully',
                'data' => new ListDestinationResource($destination),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add multiple destinations (batch).
     */
    public function addDestinationsBatch(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('upload', $list);

        $validated = $request->validate([
            'destinations' => ['required', 'array', 'max:1000'],
            'destinations.*.phone_number' => ['required', 'string'],
            'destinations.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->listService->addDestinationsBatch(
                $list->id,
                $validated['destinations'],
            );

            return response()->json([
                'message' => 'Batch processing completed',
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get destinations for a list.
     */
    public function getDestinations(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('view', $list);

        $destinations = $list->destinations()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q, $search) {
                $q->where('phone_number', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'data' => ListDestinationResource::collection($destinations),
            'meta' => [
                'current_page' => $destinations->currentPage(),
                'last_page' => $destinations->lastPage(),
                'per_page' => $destinations->perPage(),
                'total' => $destinations->total(),
            ],
        ]);
    }

    /**
     * Get version history.
     */
    public function getVersions(AutoDialerList $list): JsonResponse
    {
        $this->authorize('view', $list);

        $versions = $list->getVersionHistory();

        return response()->json([
            'data' => DistributionListResource::collection($versions),
        ]);
    }

    /**
     * Copy a list.
     */
    public function copy(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('copy', $list);

        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $copy = $this->listService->copyList($list->id, $validated['new_name']);

            return response()->json([
                'message' => 'List copied successfully',
                'data' => new DistributionListResource($copy),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Archive a list.
     */
    public function archive(AutoDialerList $list): JsonResponse
    {
        $this->authorize('archive', $list);

        try {
            $this->listService->archiveList($list->id);

            return response()->json([
                'message' => 'List archived successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Download list as CSV.
     */
    public function download(AutoDialerList $list): BinaryFileResponse
    {
        $this->authorize('download', $list);

        $filePath = $this->listService->generateCsvExport($list->id);

        return response()->download($filePath, "{$list->name}.csv");
    }

    /**
     * Download example CSV template.
     */
    public function downloadExample(): BinaryFileResponse
    {
        $content = "phone_number,description\n".
            "+14155551212,John Doe - Sales Lead\n".
            "+14155551213,Jane Smith - Support Case\n".
            "+14155551214,Bob Johnson - Follow-up Call\n";

        $filePath = tempnam(sys_get_temp_dir(), 'list_example_').'.csv';
        file_put_contents($filePath, $content);

        return response()->download($filePath, 'distribution_list_example.csv');
    }

    /**
     * Get validation errors for a failed list.
     */
    public function getValidationErrors(AutoDialerList $list): JsonResponse
    {
        $this->authorize('view', $list);

        if ($list->status->value !== 'failed') {
            return response()->json([
                'error' => 'Validation errors only available for failed lists',
            ], 400);
        }

        return response()->json([
            'data' => $list->validation_errors ?? [],
        ]);
    }

    /**
     * Delete a list (only allowed for failed lists or by Owners).
     */
    public function destroy(AutoDialerList $list): JsonResponse
    {
        $this->authorize('delete', $list);

        try {
            // Delete associated destinations first
            $list->destinations()->delete();

            // Delete the list
            $list->delete();

            return response()->json([
                'message' => 'List deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete list: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign a list to a campaign.
     */
    public function assignToCampaign(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('assign', $list);

        $validated = $request->validate([
            'campaign_id' => ['required', 'exists:auto_dialer_campaigns,id'],
        ]);

        $campaign = AutoDialerCampaign::findOrFail($validated['campaign_id']);

        // Check if campaign belongs to the same organization
        if ($campaign->organization_id !== $list->organization_id) {
            return response()->json([
                'error' => 'Campaign does not belong to this organization',
            ], 403);
        }

        // Check if campaign can accept a list
        if (! $campaign->canAcceptList()) {
            return response()->json([
                'error' => sprintf(
                    'Cannot assign list to "%s" because it is %s. Lists can only be assigned to campaigns that are Draft, Paused, or Active (but not yet running).',
                    $campaign->name,
                    $campaign->status->label()
                ),
            ], 422);
        }

        // Additional check: active campaigns can only accept lists if they haven't started running yet
        if ($campaign->status === CampaignStatus::ACTIVE && $campaign->isRunnable()) {
            return response()->json([
                'error' => sprintf(
                    'Cannot assign list to "%s" because it is currently running. Pause the campaign first, then assign the list.',
                    $campaign->name
                ),
            ], 422);
        }

        // Check if list is ready for assignment
        if (! $list->isReady()) {
            return response()->json([
                'error' => 'List is not ready for assignment',
            ], 422);
        }

        try {
            $this->listService->assignListToCampaign($list->id, $campaign->id);

            return response()->json([
                'message' => 'List assigned to campaign successfully',
                'data' => [
                    'list_id' => $list->id,
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to assign list to campaign: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset dial attempts for a single destination.
     */
    public function resetDialAttempts(AutoDialerList $list, int $destinationId): JsonResponse
    {
        $this->authorize('resetDialAttempts', $list);

        $destination = AutoDialerDestination::where('list_id', $list->id)
            ->where('id', $destinationId)
            ->first();

        if (! $destination) {
            return response()->json([
                'error' => 'Destination not found in this list',
            ], 404);
        }

        $this->destinationService->resetDialAttempts($destination);

        return response()->json([
            'message' => 'Dial attempts reset successfully',
            'data' => [
                'destination_id' => $destination->id,
                'phone_number' => $destination->phone_number,
            ],
        ]);
    }

    /**
     * Bulk reset dial attempts for multiple destinations.
     */
    public function bulkResetDialAttempts(Request $request, AutoDialerList $list): JsonResponse
    {
        $this->authorize('resetDialAttempts', $list);

        $validated = $request->validate([
            'destination_ids' => ['required', 'array'],
            'destination_ids.*' => ['integer'],
        ]);

        $destinationIds = $validated['destination_ids'];

        // Verify all destinations belong to this list
        $validCount = AutoDialerDestination::where('list_id', $list->id)
            ->whereIn('id', $destinationIds)
            ->count();

        if ($validCount !== count($destinationIds)) {
            return response()->json([
                'error' => 'One or more destinations not found in this list',
            ], 422);
        }

        $updatedCount = $this->destinationService->bulkResetDialAttempts($destinationIds);

        return response()->json([
            'message' => "Dial attempts reset for {$updatedCount} destinations",
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    /**
     * Reset all pending destinations in a list.
     */
    public function resetPendingDestinations(AutoDialerList $list): JsonResponse
    {
        $this->authorize('resetDialAttempts', $list);

        $updatedCount = $this->destinationService->resetPendingDestinations($list->id);

        return response()->json([
            'message' => "Reset {$updatedCount} pending destinations",
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    /**
     * Unassign a list from its campaign.
     */
    public function unassignFromCampaign(AutoDialerList $list): JsonResponse
    {
        $this->authorize('unassign', $list);

        // Check if list is assigned to a campaign
        if (! $list->campaign_id) {
            return response()->json([
                'error' => 'List is not assigned to any campaign',
            ], 422);
        }

        // Check if all destinations are pending with zero dial attempts
        if (! $list->canUnassign()) {
            return response()->json([
                'error' => 'Cannot unassign list: some destinations have already been dialed or are not in pending status. All entries must be pending with zero dial attempts.',
            ], 422);
        }

        try {
            $campaignName = $list->campaign?->name;

            // Update the list to unassign it
            $list->update([
                'campaign_id' => null,
                'status' => ListStatus::READY,
                'used_by_campaign_id' => null,
                'used_at' => null,
            ]);

            return response()->json([
                'message' => 'List unassigned from campaign successfully',
                'data' => [
                    'list_id' => $list->id,
                    'previous_campaign_name' => $campaignName,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to unassign list from campaign: '.$e->getMessage(),
            ], 500);
        }
    }
}
