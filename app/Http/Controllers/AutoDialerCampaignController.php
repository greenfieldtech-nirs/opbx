<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Http\Requests\UploadListRequest;
use App\Http\Resources\AutoDialerCampaignResource;
use App\Models\AutoDialerCampaign;
use App\Services\AutoDialer\CallerIdPoolService;
use App\Services\AutoDialer\CampaignLifecycleService;
use App\Services\AutoDialer\CampaignListService;
use App\Services\AutoDialer\CampaignManagementService;
use App\Services\AutoDialer\CampaignMonitorService;
use App\Services\AutoDialer\ScheduleExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AutoDialerCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignListService $listService,
        private readonly CampaignMonitorService $monitorService,
        private readonly CampaignLifecycleService $lifecycleService,
        private readonly CallerIdPoolService $callerIdService,
        private readonly CampaignManagementService $managementService,
        private readonly ScheduleExtractorService $scheduleExtractor,
    ) {}

    /**
     * List all campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerCampaign::class);

        $filters = [
            'status' => $request->status,
            'search' => $request->search,
            'per_page' => $request->per_page ?? 25,
        ];

        $campaigns = $this->managementService->listCampaigns(
            Auth::user()->organization_id,
            $filters
        );

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

        $campaign = $this->managementService->getCampaign($campaign, ['callerIds']);

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
        $organizationId = Auth::user()->organization_id;

        // Validate Caller ID Pool
        $callerIdPool = $data['caller_id_pool'] ?? [];
        $validation = $this->callerIdService->validateCallerIdPool($callerIdPool, $organizationId);

        if (! $validation['valid']) {
            return response()->json([
                'message' => 'Invalid Caller ID pool',
                'errors' => [
                    'caller_id_pool' => ['Some DIDs do not exist, do not belong to your organization, or are not active.'],
                ],
            ], 422);
        }

        // Prepare campaign data
        $campaignData = $this->buildCampaignData($data, $organizationId);

        // Create campaign with pool in transaction
        $campaign = DB::transaction(function () use ($campaignData, $callerIdPool): AutoDialerCampaign {
            $campaign = AutoDialerCampaign::create($campaignData);

            // Sync caller ID pool
            $syncData = $this->callerIdService->buildSyncData($callerIdPool);
            $campaign->callerIds()->sync($syncData);

            // Create initial stats records
            $this->callerIdService->createInitialStats($campaign, $callerIdPool);

            return $campaign;
        });

        $campaign->load('callerIds');

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

        $data = $request->validated();

        // Check if trying to modify caller_id_pool on active campaign
        if (isset($data['caller_id_pool']) && $campaign->status === CampaignStatus::ACTIVE) {
            return response()->json([
                'message' => 'Cannot modify Caller ID pool on an active campaign',
                'errors' => [
                    'caller_id_pool' => ['Please pause the campaign before modifying the Caller ID pool.'],
                ],
            ], 409);
        }

        // Extract schedule data if provided
        if (isset($data['schedule'])) {
            $scheduleData = $this->scheduleExtractor->processSchedule($data['schedule']);
            $data = array_merge($data, $scheduleData);
        }

        // Handle Caller ID Pool updates
        $callerIdPool = $data['caller_id_pool'] ?? null;
        unset($data['caller_id_pool']);

        DB::transaction(function () use ($campaign, $data, $callerIdPool): void {
            $campaign->update($data);

            if ($callerIdPool !== null) {
                $result = $this->callerIdService->syncCallerIdPool($campaign, $callerIdPool);

                if (! $result['success']) {
                    throw new \InvalidArgumentException($result['message']);
                }
            }
        });

        $campaign->load('callerIds');

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

        $result = $this->lifecycleService->start($campaign);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['code'] ?? 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => new AutoDialerCampaignResource($result['campaign']),
        ]);
    }

    /**
     * Pause a campaign.
     */
    public function pause(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('pause', $campaign);

        $result = $this->lifecycleService->pause($campaign);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['code'] ?? 409);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => new AutoDialerCampaignResource($result['campaign']),
        ]);
    }

    /**
     * Resume a campaign.
     */
    public function resume(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('resume', $campaign);

        $result = $this->lifecycleService->resume($campaign);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['code'] ?? 409);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => new AutoDialerCampaignResource($result['campaign']),
        ]);
    }

    /**
     * Reset the CAC counter for a campaign.
     *
     * This is useful when the CAC counter gets stuck due to missed CDR
     * webhooks, preventing new calls from being initiated.
     */
    public function resetCac(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        try {
            $redis = \Illuminate\Support\Facades\Redis::connection('dialer');
            $key = "dialer:cac:{$campaign->id}:active";
            $currentValue = $redis->get($key);

            $redis->set($key, 0);

            return response()->json([
                'message' => 'CAC counter reset successfully',
                'campaign_id' => $campaign->id,
                'previous_value' => $currentValue ? (int) $currentValue : 0,
                'new_value' => 0,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reset CAC counter',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive a campaign.
     */
    public function archive(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('archive', $campaign);

        $result = $this->lifecycleService->archive($campaign);

        return response()->json([
            'message' => $result['message'],
            'data' => new AutoDialerCampaignResource($result['campaign']),
        ]);
    }

    /**
     * Upload a destination list.
     */
    public function uploadList(UploadListRequest $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('uploadList', $campaign);

        $file = $request->file('file');
        $name = $request->input('name');

        $result = $this->listService->uploadList($campaign, $file, $name);

        return response()->json([
            'message' => 'List uploaded successfully',
            'data' => $result,
        ]);
    }

    /**
     * Get list for a campaign.
     */
    public function getList(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $listDetails = $this->listService->getListDetails($campaign);

        if ($listDetails === null) {
            return response()->json([
                'message' => 'No list uploaded for this campaign',
            ], 404);
        }

        return response()->json([
            'data' => $listDetails,
        ]);
    }

    /**
     * Delete list from a campaign.
     */
    public function deleteList(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('deleteList', $campaign);

        $this->listService->deleteList($campaign);

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

        $filters = [
            'status' => $request->status,
            'per_page' => $request->per_page ?? 50,
        ];

        $destinations = $this->managementService->getDestinations($campaign, $filters);

        return response()->json([
            'data' => $destinations,
        ]);
    }

    /**
     * Get real-time concurrency status for a campaign.
     */
    public function concurrency(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $data = $this->monitorService->getConcurrencyStatus($campaign);

        return response()->json(['data' => $data]);
    }

    /**
     * Get real-time monitor summary for all active/paused campaigns.
     */
    public function monitorSummary(): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerCampaign::class);

        $organizationId = Auth::user()->organization_id;
        $data = $this->monitorService->getMonitorSummary($organizationId);

        return response()->json(['data' => $data]);
    }

    /**
     * Get detailed monitor view for a single campaign.
     */
    public function monitorDetail(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $data = $this->monitorService->getMonitorDetail($campaign);

        return response()->json(['data' => $data]);
    }

    /**
     * Get available DIDs for Caller ID pool selection.
     */
    public function getAvailableCallerIds(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerCampaign::class);

        $organizationId = Auth::user()->organization_id;
        $excludeCampaignId = $request->query('exclude_campaign_id');

        $dids = $this->callerIdService->getAvailableDids($organizationId, $excludeCampaignId);
        $formattedDids = $this->callerIdService->formatAvailableDids($dids);

        return response()->json(['data' => $formattedDids]);
    }

    /**
     * Get Caller ID statistics for a campaign.
     */
    public function getCallerIdStats(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $data = $this->callerIdService->getCallerIdStats($campaign);

        return response()->json($data);
    }

    /**
     * Reset the Caller ID cycle (Round Robin only).
     */
    public function resetCallerIdCycle(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $result = $this->callerIdService->resetCallerIdCycle($campaign);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
                'errors' => [
                    'status' => [$result['message']],
                ],
            ], $result['code'] ?? 409);
        }

        return response()->json([
            'message' => $result['message'],
            'campaign_id' => $campaign->id,
            'strategy' => $campaign->caller_id_strategy?->value,
            'next_index' => $result['next_index'],
        ]);
    }

    /**
     * Build campaign data array from validated request data.
     *
     * @param  array<string, mixed>  $data  Validated request data
     * @param  int  $organizationId  Organization ID
     * @return array<string, mixed>
     */
    private function buildCampaignData(array $data, int $organizationId): array
    {
        $schedule = $data['schedule'] ?? [];
        $scheduleData = $this->scheduleExtractor->processSchedule($schedule);

        return [
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => CampaignStatus::DRAFT,
            'auto_start' => $data['auto_start'] ?? false,
            'routing_destination_type' => $data['routing_destination_type'],
            'routing_destination_id' => $data['routing_destination_id'] ?? null,
            'dial_timeout' => $data['dial_timeout'],
            'destination_connect' => $data['destination_connect'],
            'caller_id' => $data['caller_id'],
            'caller_id_strategy' => $data['caller_id_strategy'],
            'caller_id_pool_enabled' => true,
            'max_dial_attempts' => $data['max_dial_attempts'],
            'concurrent_active_calls' => $data['concurrent_active_calls'],
            'calls_per_second' => $data['calls_per_second'] ?? 1,
            'total_destinations' => 0,
            'completed_calls' => 0,
            'failed_calls' => 0,
            'pending_calls' => 0,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'timezone' => $data['timezone'],
            'time_limit' => $data['time_limit'] ?? null,
            'record_calls' => $data['record_calls'] ?? false,
            'action_voicemail' => $data['action_voicemail'] ?? null,
            'action_human' => $data['action_human'] ?? null,
            'action_unknown' => $data['action_unknown'] ?? null,
            'retry_on_voicemail' => $data['retry_on_voicemail'] ?? false,
            'days_active' => $scheduleData['days_active'],
            'schedule' => $schedule,
            'start_time' => $scheduleData['start_time'],
            'end_time' => $scheduleData['end_time'],
        ];
    }
}
