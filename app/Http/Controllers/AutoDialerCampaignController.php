<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Http\Requests\UploadListRequest;
use App\Http\Resources\AutoDialerCampaignResource;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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

        // Mark all in-flight sessions as failed/cancelled — once paused,
        // these are orphans whose CDRs may never arrive.
        $staleCount = OrganizationScope::bypass(function () use ($campaign): int {
            return AutoDialerCallSession::where('campaign_id', $campaign->id)
                ->whereIn('status', ['initiated', 'ringing', 'answered'])
                ->update([
                    'status' => 'failed',
                    'disposition' => 'cancelled',
                    'completed_at' => now(),
                ]);
        });

        if ($staleCount > 0) {
            Log::info('Cleaned up stale sessions on campaign pause', [
                'campaign_id' => $campaign->id,
                'stale_sessions' => $staleCount,
            ]);
        }

        // Reset the CAC counter — any in-flight calls are no longer tracked
        // by the worker once the campaign is paused.
        try {
            $dialerRedis = Redis::connection('dialer');
            $dialerRedis->set("dialer:cac:{$campaign->id}:active", 0);
        } catch (\Exception $e) {
            Log::error('Failed to reset CAC counter on pause', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Bust monitor cache so the UI reflects the change immediately
        $this->bustMonitorCache($campaign->organization_id, $campaign->id);

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

        // Bust monitor cache so the UI reflects the change immediately
        $this->bustMonitorCache($campaign->organization_id, $campaign->id);

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

    /**
     * Get real-time monitor summary for all active/paused campaigns.
     *
     * Returns a bird's-eye view of all campaigns with their concurrency data,
     * progress statistics, and dialer worker health status.
     */
    public function monitorSummary(): JsonResponse
    {
        $this->authorize('viewAny', AutoDialerCampaign::class);

        $organizationId = Auth::user()->organization_id;
        $cacheKey = "monitor:summary:org:{$organizationId}";

        $data = Cache::remember($cacheKey, 5, function () use ($organizationId): array {
            // Query active and paused campaigns for this organization
            $campaigns = AutoDialerCampaign::forOrganization($organizationId)
                ->whereIn('status', [CampaignStatus::ACTIVE, CampaignStatus::PAUSED])
                ->get();

            $campaignData = [];
            $totalActiveCalls = 0;
            $totalCacCapacity = 0;
            $activeCampaignCount = 0;
            $pausedCampaignCount = 0;

            foreach ($campaigns as $campaign) {
                // Read active calls from the Go worker's unprefixed Redis key
                $dialerRedis = Redis::connection('dialer');
                $workerKey = "dialer:cac:{$campaign->id}:active";
                $activeCalls = max(0, (int) $dialerRedis->get($workerKey));

                // Paused campaigns cannot have active calls — force to 0
                if ($campaign->status === CampaignStatus::PAUSED && $activeCalls > 0) {
                    $dialerRedis->set($workerKey, 0);
                    $activeCalls = 0;
                }

                $cac = $campaign->concurrent_active_calls;
                $cacUtilization = $cac > 0 ? round(($activeCalls / $cac) * 100, 1) : 0;

                $totalActiveCalls += $activeCalls;
                $totalCacCapacity += $cac;

                if ($campaign->status === CampaignStatus::ACTIVE) {
                    $activeCampaignCount++;
                } else {
                    $pausedCampaignCount++;
                }

                // Check rate limit status
                $isRateLimited = $campaign->status === CampaignStatus::PAUSED &&
                    $campaign->pause_reason === 'cloudonix_rate_limit';

                // Clean up stale dialing destinations (stuck > 5 min) before computing stats.
                // These are calls that were initiated but never received a CDR callback.
                // Reset them to pending so the worker can retry them.
                OrganizationScope::bypass(function () use ($campaign): void {
                    $listIds = AutoDialerList::where('campaign_id', $campaign->id)->pluck('id');
                    if ($listIds->isEmpty()) {
                        return;
                    }
                    AutoDialerDestination::whereIn('list_id', $listIds)
                        ->where('status', DestinationStatus::DIALING)
                        ->where('last_dialed_at', '<', now()->subMinutes(5))
                        ->update(['status' => DestinationStatus::PENDING]);
                });

                // Compute ALL statistics from actual destination data (model counters drift)
                $destStats = OrganizationScope::bypass(function () use ($campaign): array {
                    $listIds = AutoDialerList::where('campaign_id', $campaign->id)->pluck('id');
                    if ($listIds->isEmpty()) {
                        return ['total' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'dialing' => 0];
                    }

                    $counts = AutoDialerDestination::whereIn('list_id', $listIds)
                        ->selectRaw("COUNT(*) as total,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN status = 'dialing' THEN 1 ELSE 0 END) as dialing")
                        ->first();

                    return [
                        'total' => (int) $counts->total,
                        'completed' => (int) $counts->completed,
                        'failed' => (int) $counts->failed,
                        'pending' => (int) $counts->pending,
                        'dialing' => (int) $counts->dialing,
                    ];
                });

                $processed = $destStats['completed'] + $destStats['failed'];
                $progressPercentage = $destStats['total'] > 0
                    ? (int) round(($processed / $destStats['total']) * 100)
                    : 0;

                $campaignData[] = [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status->value,
                    'progress_percentage' => $progressPercentage,
                    'total_destinations' => $destStats['total'],
                    'completed_calls' => $destStats['completed'],
                    'failed_calls' => $destStats['failed'],
                    'pending_calls' => $destStats['pending'],
                    'dialing_calls' => $destStats['dialing'],
                    'concurrent_active_calls' => $cac,
                    'active_calls' => $activeCalls,
                    'cac_utilization' => $cacUtilization,
                    'rate_limit_status' => [
                        'is_rate_limited' => $isRateLimited,
                        'pause_reason' => $campaign->pause_reason,
                        'resumes_at' => $campaign->resume_at?->toIso8601String(),
                    ],
                    'caller_id' => $campaign->caller_id,
                    'routing_destination_type' => $campaign->routing_destination_type->value,
                    'routing_destination_label' => $campaign->getRoutingDestinationLabel(),
                    'start_date' => $campaign->start_date?->toDateString(),
                    'end_date' => $campaign->end_date?->toDateString(),
                ];
            }

            // Calculate overall utilization
            $overallUtilization = $totalCacCapacity > 0
                ? round(($totalActiveCalls / $totalCacCapacity) * 100, 1)
                : 0;

            // Get worker health status
            $workerHealth = $this->getDialerWorkerHealth();

            return [
                'campaigns' => $campaignData,
                'totals' => [
                    'active_campaigns' => $activeCampaignCount,
                    'paused_campaigns' => $pausedCampaignCount,
                    'total_active_calls' => $totalActiveCalls,
                    'total_cac_capacity' => $totalCacCapacity,
                    'overall_utilization' => $overallUtilization,
                ],
                'worker_health' => $workerHealth,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Get detailed monitor view for a single campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get details for
     */
    public function monitorDetail(AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $cacheKey = "monitor:detail:campaign:{$campaign->id}";

        $data = Cache::remember($cacheKey, 10, function () use ($campaign): array {
            // Read active calls from the Go worker's unprefixed Redis key
            $dialerRedis = Redis::connection('dialer');
            $workerKey = "dialer:cac:{$campaign->id}:active";
            $activeCalls = max(0, (int) $dialerRedis->get($workerKey));

            // Paused campaigns cannot have active calls — force to 0 and clean up
            if ($campaign->status === CampaignStatus::PAUSED && $activeCalls > 0) {
                $dialerRedis->set($workerKey, 0);
                $activeCalls = 0;
            }

            $cac = $campaign->concurrent_active_calls;
            $cacUtilization = $cac > 0 ? round(($activeCalls / $cac) * 100, 1) : 0;

            // Get disposition breakdown - bypass scope since campaign is already scoped
            $dispositions = OrganizationScope::bypass(function () use ($campaign): array {
                $results = AutoDialerCallSession::where('campaign_id', $campaign->id)
                    ->whereNotNull('disposition')
                    ->selectRaw('disposition, COUNT(*) as count')
                    ->groupBy('disposition')
                    ->pluck('count', 'disposition')
                    ->toArray();

                // Ensure all disposition keys exist with 0 if not present
                $allDispositions = [
                    'answered' => 0,
                    'completed' => 0,
                    'busy' => 0,
                    'no_answer' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                    'congestion' => 0,
                ];

                // Map Cloudonix disposition values (uppercase) to our keys (lowercase)
                $dispositionMap = [
                    'answer' => 'answered',
                    'answered' => 'answered',
                    'completed' => 'completed',
                    'busy' => 'busy',
                    'no-answer' => 'no_answer',
                    'no_answer' => 'no_answer',
                    'noanswer' => 'no_answer',
                    'failed' => 'failed',
                    'cancelled' => 'cancelled',
                    'cancel' => 'cancelled',
                    'congestion' => 'congestion',
                ];

                foreach ($results as $disposition => $count) {
                    $normalized = $dispositionMap[strtolower($disposition)] ?? strtolower($disposition);
                    if (array_key_exists($normalized, $allDispositions)) {
                        $allDispositions[$normalized] += (int) $count;
                    }
                }

                return $allDispositions;
            });

            // Get average duration and billsec - bypass scope
            $statistics = OrganizationScope::bypass(function () use ($campaign): array {
                $avgDuration = AutoDialerCallSession::where('campaign_id', $campaign->id)
                    ->where('status', 'completed')
                    ->where('duration', '>', 0)
                    ->avg('duration');

                $avgBillsec = AutoDialerCallSession::where('campaign_id', $campaign->id)
                    ->where('status', 'completed')
                    ->where('billsec', '>', 0)
                    ->avg('billsec');

                return [
                    'avg_duration_seconds' => $avgDuration ? (int) round((float) $avgDuration) : 0,
                    'avg_billsec_seconds' => $avgBillsec ? (int) round((float) $avgBillsec) : 0,
                ];
            });

            // Check rate limit status
            $isRateLimited = $campaign->status === CampaignStatus::PAUSED &&
                $campaign->pause_reason === 'cloudonix_rate_limit';
            $canResumeNow = $campaign->resume_at ? now()->gte($campaign->resume_at) : true;

            return [
                'campaign' => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status->value,
                    'concurrent_active_calls' => $cac,
                    'active_calls' => $activeCalls,
                    'cac_utilization' => $cacUtilization,
                ],
                'statistics' => [
                    'total_destinations' => ($detailStats = OrganizationScope::bypass(function () use ($campaign): array {
                        $listIds = AutoDialerList::where('campaign_id', $campaign->id)->pluck('id');
                        if ($listIds->isEmpty()) {
                            return ['total' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'dialing' => 0];
                        }

                        // Clean up stale dialing destinations (stuck > 5 min)
                        AutoDialerDestination::whereIn('list_id', $listIds)
                            ->where('status', DestinationStatus::DIALING)
                            ->where('last_dialed_at', '<', now()->subMinutes(5))
                            ->update(['status' => DestinationStatus::PENDING]);

                        $counts = AutoDialerDestination::whereIn('list_id', $listIds)
                            ->selectRaw("COUNT(*) as total,
                                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN status = 'dialing' THEN 1 ELSE 0 END) as dialing")
                            ->first();

                        return [
                            'total' => (int) $counts->total,
                            'completed' => (int) $counts->completed,
                            'failed' => (int) $counts->failed,
                            'pending' => (int) $counts->pending,
                            'dialing' => (int) $counts->dialing,
                        ];
                    }))['total'],
                    'completed_calls' => $detailStats['completed'],
                    'failed_calls' => $detailStats['failed'],
                    'pending_calls' => $detailStats['pending'],
                    'dialing_calls' => $detailStats['dialing'],
                    'progress_percentage' => $detailStats['total'] > 0
                        ? (int) round((($detailStats['completed'] + $detailStats['failed']) / $detailStats['total']) * 100)
                        : 0,
                    'avg_duration_seconds' => $statistics['avg_duration_seconds'],
                    'avg_billsec_seconds' => $statistics['avg_billsec_seconds'],
                ],
                'dispositions' => $dispositions,
                'rate_limit_status' => [
                    'is_rate_limited' => $isRateLimited,
                    'pause_reason' => $campaign->pause_reason,
                    'resumes_at' => $campaign->resume_at?->toIso8601String(),
                    'can_resume_now' => $canResumeNow,
                ],
            ];
        });

        // Active sessions: queried fresh (never cached).
        // Paused campaigns have no active calls — clean up any stale sessions.
        if ($campaign->status === CampaignStatus::PAUSED) {
            OrganizationScope::bypass(function () use ($campaign): void {
                AutoDialerCallSession::where('campaign_id', $campaign->id)
                    ->whereIn('status', ['initiated', 'ringing', 'answered'])
                    ->update([
                        'status' => 'failed',
                        'disposition' => 'cancelled',
                        'completed_at' => now(),
                    ]);
            });
            $data['active_sessions'] = [];
        } else {
            $data['active_sessions'] = OrganizationScope::bypass(function () use ($campaign): array {
                return AutoDialerCallSession::where('campaign_id', $campaign->id)
                    ->whereIn('status', ['initiated', 'ringing', 'answered'])
                    ->where('initiated_at', '>=', now()->subMinutes(5))
                    ->orderBy('initiated_at', 'desc')
                    ->get(['id', 'phone_number', 'status', 'call_id', 'initiated_at'])
                    ->map(fn ($s) => [
                        'id' => $s->id,
                        'phone_number' => $s->phone_number,
                        'status' => $s->status,
                        'call_id' => $s->call_id,
                        'initiated_at' => $s->initiated_at?->toIso8601String(),
                        'duration_seconds' => $s->initiated_at ? (int) now()->diffInSeconds($s->initiated_at) : 0,
                    ])
                    ->toArray();
            });
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Get dialer worker health status.
     *
     * @return array<string, mixed>
     */
    private function getDialerWorkerHealth(): array
    {
        $healthUrl = config('services.dialer_worker.health_url');

        if (empty($healthUrl)) {
            return [
                'status' => 'unknown',
                'active_campaigns' => 0,
                'active_calls' => 0,
                'queue_depth' => 0,
            ];
        }

        try {
            $response = Http::timeout(5)->get($healthUrl);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'status' => $data['status'] ?? 'healthy',
                    'active_campaigns' => $data['active_campaigns'] ?? 0,
                    'active_calls' => $data['active_calls'] ?? 0,
                    'queue_depth' => $data['queue_depth'] ?? 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch dialer worker health', [
                'url' => $healthUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'status' => 'offline',
            'active_campaigns' => 0,
            'active_calls' => 0,
            'queue_depth' => 0,
        ];
    }

    /**
     * Bust the monitor summary and detail cache so the UI reflects changes immediately.
     */
    private function bustMonitorCache(int $organizationId, int $campaignId): void
    {
        Cache::forget("monitor:summary:{$organizationId}");
        Cache::forget("monitor:detail:{$campaignId}");
    }
}
