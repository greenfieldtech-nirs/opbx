<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Http\Requests\UploadListRequest;
use App\Http\Resources\AutoDialerCampaignResource;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AutoDialerCampaignController extends Controller
{
    /**
     * List all campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerCampaign::class);

        $campaigns = AutoDialerCampaign::forOrganization(Auth::user()->organization_id)
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'data' => AutoDialerCampaignResource::collection($campaigns),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Get a single campaign.
     */
    public function show(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return response()->json([
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Create a new campaign.
     */
    public function store(CreateCampaignRequest $request): JsonResponse
    {
        $this->authorize('create', AutoDialerCampaign::class);

        $data = $request->validated();
        $data['organization_id'] = Auth::user()->organization_id;
        $data['status'] = CampaignStatus::DRAFT;
        $data['total_destinations'] = 0;
        $data['completed_calls'] = 0;
        $data['failed_calls'] = 0;
        $data['pending_calls'] = 0;

        $campaign = AutoDialerCampaign::create($data);

        return response()->json([
            'message' => 'Campaign created successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ], 201);
    }

    /**
     * Update a campaign.
     */
    public function update(UpdateCampaignRequest $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $campaign->update($request->validated());

        return response()->json([
            'message' => 'Campaign updated successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Delete a campaign.
     */
    public function destroy(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('delete', $campaign);

        $campaign->delete();

        return response()->json([
            'message' => 'Campaign deleted successfully',
        ]);
    }

    /**
     * Start a campaign.
     */
    public function start(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('start', $campaign);

        if (! $campaign->hasList()) {
            return response()->json([
                'message' => 'Cannot start campaign without a destination list',
            ], 422);
        }

        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);

        return response()->json([
            'message' => 'Campaign started successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Pause a campaign.
     */
    public function pause(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('pause', $campaign);

        $campaign->update([
            'status' => CampaignStatus::PAUSED,
        ]);

        return response()->json([
            'message' => 'Campaign paused successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Resume a campaign.
     */
    public function resume(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('resume', $campaign);

        $campaign->update([
            'status' => CampaignStatus::ACTIVE,
        ]);

        return response()->json([
            'message' => 'Campaign resumed successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Archive a campaign.
     */
    public function archive(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('archive', $campaign);

        $campaign->update([
            'status' => CampaignStatus::ARCHIVED,
        ]);

        return response()->json([
            'message' => 'Campaign archived successfully',
            'data' => new AutoDialerCampaignResource($campaign),
        ]);
    }

    /**
     * Upload a destination list.
     */
    public function uploadList(UploadListRequest $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('uploadList', $campaign);

        $file = $request->file('file');
        $path = $file->store('auto-dialer-lists');

        // Create list record
        $list = AutoDialerList::create([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'name' => $request->input('name', $file->getClientOriginalName()),
            'status' => 'processing',
            'original_filename' => $file->getClientOriginalName(),
        ]);

        // Process CSV (basic implementation)
        $this->processCsvFile($path, $campaign, $list);

        return response()->json([
            'message' => 'List uploaded successfully',
            'data' => [
                'list_id' => $list->id,
                'total_rows' => $list->total_rows,
                'valid_rows' => $list->valid_rows,
                'invalid_rows' => $list->invalid_rows,
            ],
        ]);
    }

    /**
     * Get list for a campaign.
     */
    public function getList(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        if (! $campaign->list) {
            return response()->json([
                'message' => 'No list uploaded for this campaign',
            ], 404);
        }

        return response()->json([
            'data' => $campaign->list,
        ]);
    }

    /**
     * Delete list from a campaign.
     */
    public function deleteList(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('deleteList', $campaign);

        if ($campaign->list) {
            $campaign->list->destinations()->delete();
            $campaign->list->delete();
        }

        return response()->json([
            'message' => 'List deleted successfully',
        ]);
    }

    /**
     * Get destinations for a campaign.
     */
    public function getDestinations(Request $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $destinations = $campaign->destinations()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'data' => $destinations,
        ]);
    }

    /**
     * Process CSV file (basic implementation).
     */
    private function processCsvFile(string $path, AutoDialerCampaign $campaign, AutoDialerList $list): void
    {
        $fullPath = Storage::path($path);
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            return;
        }

        // Read header
        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return;
        }

        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $destinations = [];

        while (($row = fgetcsv($handle)) !== false) {
            $totalRows++;

            if (count($row) < 1) {
                $invalidRows++;

                continue;
            }

            $phoneNumber = trim($row[0]);
            $description = trim($row[1] ?? '');

            // Basic E.164 validation
            if (! preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber)) {
                $invalidRows++;

                continue;
            }

            $destinations[] = [
                'organization_id' => $campaign->organization_id,
                'list_id' => $list->id,
                'phone_number' => $phoneNumber,
                'description' => $description,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $validRows++;

            // Batch insert every 1000 records
            if (count($destinations) >= 1000) {
                AutoDialerDestination::insert($destinations);
                $destinations = [];
            }
        }

        fclose($handle);

        // Insert remaining records
        if (! empty($destinations)) {
            AutoDialerDestination::insert($destinations);
        }

        // Remove duplicates
        $this->removeDuplicateDestinations($list->id);

        // Update list
        $uniqueCount = AutoDialerDestination::where('list_id', $list->id)->count();
        $list->update([
            'status' => 'ready',
            'processed_at' => now(),
            'total_rows' => $totalRows,
            'valid_rows' => $uniqueCount,
            'invalid_rows' => $invalidRows + ($validRows - $uniqueCount),
        ]);

        // Update campaign stats
        $campaign->update([
            'total_destinations' => $uniqueCount,
            'pending_calls' => $uniqueCount,
        ]);

        // Clean up file
        Storage::delete($path);
    }

    /**
     * Remove duplicate phone numbers from list.
     */
    private function removeDuplicateDestinations(int $listId): void
    {
        $duplicates = AutoDialerDestination::select('phone_number')
            ->where('list_id', $listId)
            ->groupBy('phone_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone_number');

        foreach ($duplicates as $phoneNumber) {
            $ids = AutoDialerDestination::where('list_id', $listId)
                ->where('phone_number', $phoneNumber)
                ->orderBy('id')
                ->pluck('id');

            // Keep first, delete rest
            $ids->shift();
            AutoDialerDestination::whereIn('id', $ids)->delete();
        }
    }
}
