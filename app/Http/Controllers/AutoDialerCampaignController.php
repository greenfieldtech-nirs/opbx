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

        // Extract days_active and legacy time fields from schedule
        $schedule = $data['schedule'] ?? [];
        $data['days_active'] = $this->extractDaysActiveFromSchedule($schedule);

        // Extract start_time and end_time from first enabled day's first time range
        $timeRange = $this->extractTimeRangeFromSchedule($schedule);
        $data['start_time'] = $timeRange['start_time'] ?? 9;
        $data['end_time'] = $timeRange['end_time'] ?? 17;

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

        $data = $request->validated();

        // Extract days_active and legacy time fields from schedule if provided
        if (isset($data['schedule'])) {
            $data['days_active'] = $this->extractDaysActiveFromSchedule($data['schedule']);

            // Extract start_time and end_time from first enabled day's first time range
            $timeRange = $this->extractTimeRangeFromSchedule($data['schedule']);
            $data['start_time'] = $timeRange['start_time'] ?? 9;
            $data['end_time'] = $timeRange['end_time'] ?? 17;
        }

        $campaign->update($data);

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
        $header = fgetcsv($handle, escape: '\\');
        if (! $header) {
            fclose($handle);

            return;
        }

        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $destinations = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
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

    /**
     * Extract days_active array from schedule data.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string>
     */
    private function extractDaysActiveFromSchedule(array $schedule): array
    {
        $daysActive = [];

        foreach ($schedule as $day => $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                $daysActive[] = strtolower($day);
            }
        }

        return $daysActive;
    }

    /**
     * Extract start_time and end_time from first enabled day's first time range.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<string, int|null>
     */
    private function extractTimeRangeFromSchedule(array $schedule): array
    {
        foreach ($schedule as $config) {
            if (is_array($config) && ($config['enabled'] ?? false)) {
                $timeRanges = $config['time_ranges'] ?? [];
                if (! empty($timeRanges) && is_array($timeRanges[0])) {
                    $startTime = $timeRanges[0]['start_time'] ?? '09:00';
                    $endTime = $timeRanges[0]['end_time'] ?? '17:00';

                    return [
                        'start_time' => (int) substr($startTime, 0, 2),
                        'end_time' => (int) substr($endTime, 0, 2),
                    ];
                }
            }
        }

        return ['start_time' => 9, 'end_time' => 17];
    }

    /**
     * Get real-time concurrency status for a campaign.
     *
     * Returns the current CAC (Concurrent Active Calls) utilization,
     * active sessions, and API rate information for real-time monitoring.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get status for
     */
    public function concurrency(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $cac = $campaign->concurrent_active_calls;

        // Get current active count from Redis
        $counterKey = "campaign:{$campaign->id}:concurrency_counter";
        $activeCount = (int) Redis::get($counterKey) ?? 0;

        // Ensure non-negative
        if ($activeCount < 0) {
            $activeCount = 0;
        }

        // Get active sessions from Redis
        $sessionsKey = "campaign:{$campaign->id}:active_sessions";
        $sessionTokens = Redis::smembers($sessionsKey) ?? [];

        // Get active session details from database
        $activeSessions = [];
        if (! empty($sessionTokens)) {
            $sessions = AutoDialerCallSession::whereIn('session_token', $sessionTokens)
                ->with('destination')
                ->where('campaign_id', $campaign->id)
                ->get();

            foreach ($sessions as $session) {
                $activeSessions[] = [
                    'session_id' => $session->id,
                    'session_token' => $session->session_token,
                    'destination_id' => $session->destination_id,
                    'phone_number' => $session->destination?->phone_number ?? 'unknown',
                    'started_at' => $session->started_at?->toIso8601String(),
                    'duration_seconds' => $session->started_at ? now()->diffInSeconds($session->started_at) : 0,
                ];
            }
        }

        // Calculate utilization percentage
        $utilizationPercentage = $cac > 0 ? round(($activeCount / $cac) * 100, 1) : 0;

        // Calculate API interval
        $apiIntervalSeconds = $campaign->getApiIntervalSeconds();

        return response()->json([
            'data' => [
                'cac_limit' => $cac,
                'active_calls' => $activeCount,
                'available_slots' => max(0, $cac - $activeCount),
                'utilization_percentage' => $utilizationPercentage,
                'api_interval_seconds' => $apiIntervalSeconds,
                'active_sessions' => $activeSessions,
                'rate_limit_status' => [
                    'is_rate_limited' => $campaign->status === CampaignStatus::PAUSED &&
                                         $campaign->pause_reason === 'cloudonix_rate_limit',
                    'pause_reason' => $campaign->pause_reason,
                    'resumes_at' => $campaign->resume_at?->toIso8601String(),
                    'can_resume_now' => $campaign->resume_at ? now()->gte($campaign->resume_at) : true,
                ],
            ],
        ]);
    }
}
