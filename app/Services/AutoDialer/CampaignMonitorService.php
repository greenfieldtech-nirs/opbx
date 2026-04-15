<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Campaign Monitor Service
 *
 * Handles campaign monitoring, statistics, and status tracking for real-time visibility.
 */
class CampaignMonitorService
{
    private const SUMMARY_CACHE_TTL = 5; // seconds

    private const DETAIL_CACHE_TTL = 10; // seconds

    private const DISPOSITION_MAP = [
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

    private const DEFAULT_DISPOSITIONS = [
        'answered' => 0,
        'completed' => 0,
        'busy' => 0,
        'no_answer' => 0,
        'failed' => 0,
        'cancelled' => 0,
        'congestion' => 0,
    ];

    /**
     * Get real-time concurrency status for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get status for
     * @return array<string, mixed> Concurrency data including active calls and utilization
     */
    public function getConcurrencyStatus(AutoDialerCampaign $campaign): array
    {
        $cac = $campaign->concurrent_active_calls;
        $activeCount = $this->getActiveCallCount($campaign->id);
        $sessionTokens = Redis::smembers("campaign:{$campaign->id}:active_sessions") ?? [];
        $activeSessions = $this->getActiveSessionDetails($campaign->id, $sessionTokens);

        return [
            'cac_limit' => $cac,
            'active_calls' => $activeCount,
            'available_slots' => max(0, $cac - $activeCount),
            'utilization_percentage' => $this->calculateUtilization($activeCount, $cac),
            'api_interval_seconds' => $campaign->getApiIntervalMilliseconds() / 1000,
            'active_sessions' => $activeSessions,
            'rate_limit_status' => $this->getRateLimitStatus($campaign),
        ];
    }

    /**
     * Get real-time monitor summary for all active/paused campaigns in an organization.
     *
     * @param  int  $organizationId  The organization ID to get summary for
     * @return array<string, mixed> Summary data with campaigns, totals, and worker health
     */
    public function getMonitorSummary(int $organizationId): array
    {
        $cacheKey = "monitor:summary:org:{$organizationId}";

        return Cache::remember($cacheKey, self::SUMMARY_CACHE_TTL, function () use ($organizationId): array {
            $campaigns = AutoDialerCampaign::forOrganization($organizationId)
                ->whereIn('status', [CampaignStatus::ACTIVE, CampaignStatus::PAUSED])
                ->get();

            $campaignData = [];
            $totals = [
                'active_campaigns' => 0,
                'paused_campaigns' => 0,
                'total_active_calls' => 0,
                'total_cac_capacity' => 0,
            ];

            foreach ($campaigns as $campaign) {
                $campaignInfo = $this->buildCampaignMonitorInfo($campaign);
                $campaignData[] = $campaignInfo;

                $totals['total_active_calls'] += $campaignInfo['active_calls'];
                $totals['total_cac_capacity'] += $campaignInfo['concurrent_active_calls'];

                if ($campaign->status === CampaignStatus::ACTIVE) {
                    $totals['active_campaigns']++;
                } else {
                    $totals['paused_campaigns']++;
                }
            }

            $overallUtilization = $totals['total_cac_capacity'] > 0
                ? round(($totals['total_active_calls'] / $totals['total_cac_capacity']) * 100, 1)
                : 0;

            return [
                'campaigns' => $campaignData,
                'totals' => [
                    'active_campaigns' => $totals['active_campaigns'],
                    'paused_campaigns' => $totals['paused_campaigns'],
                    'total_active_calls' => $totals['total_active_calls'],
                    'total_cac_capacity' => $totals['total_cac_capacity'],
                    'overall_utilization' => $overallUtilization,
                ],
                'worker_health' => $this->getDialerWorkerHealth(),
            ];
        });
    }

    /**
     * Get detailed monitor view for a single campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get details for
     * @return array<string, mixed> Detailed campaign data with statistics
     */
    public function getMonitorDetail(AutoDialerCampaign $campaign): array
    {
        $cacheKey = "monitor:detail:campaign:{$campaign->id}";

        $data = Cache::remember($cacheKey, self::DETAIL_CACHE_TTL, function () use ($campaign): array {
            $activeCalls = $this->getWorkerActiveCalls($campaign->id);

            // Force 0 active calls for paused campaigns
            if ($campaign->status === CampaignStatus::PAUSED && $activeCalls > 0) {
                $this->setWorkerActiveCalls($campaign->id, 0);
                $activeCalls = 0;
            }

            $cac = $campaign->concurrent_active_calls;
            $stats = $this->getDestinationStatistics($campaign);
            $processed = $stats['completed'] + $stats['failed'];

            return [
                'campaign' => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->status->value,
                    'concurrent_active_calls' => $cac,
                    'active_calls' => $activeCalls,
                    'cac_utilization' => $this->calculateUtilization($activeCalls, $cac),
                ],
                'statistics' => [
                    'total_destinations' => $stats['total'],
                    'completed_calls' => $stats['completed'],
                    'failed_calls' => $stats['failed'],
                    'pending_calls' => $stats['pending'],
                    'dialing_calls' => $stats['dialing'],
                    'progress_percentage' => $stats['total'] > 0
                        ? (int) round(($processed / $stats['total']) * 100)
                        : 0,
                    'avg_duration_seconds' => $this->getAverageDuration($campaign->id),
                    'avg_billsec_seconds' => $this->getAverageBillsec($campaign->id),
                ],
                'dispositions' => $this->getDispositions($campaign->id),
                'rate_limit_status' => $this->getRateLimitStatus($campaign),
            ];
        });

        // Active sessions are never cached
        $data['active_sessions'] = $this->getActiveSessions($campaign);

        return $data;
    }

    /**
     * Bust the monitor cache for a campaign and its organization.
     *
     * @param  int  $organizationId  The organization ID
     * @param  int  $campaignId  The campaign ID
     */
    public function bustMonitorCache(int $organizationId, int $campaignId): void
    {
        Cache::forget("monitor:summary:{$organizationId}");
        Cache::forget("monitor:detail:{$campaignId}");
    }

    /**
     * Get the active call count from Redis.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function getActiveCallCount(int $campaignId): int
    {
        $count = (int) Redis::get("campaign:{$campaignId}:concurrency_counter");

        return max(0, $count);
    }

    /**
     * Get active calls from the worker's Redis key.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function getWorkerActiveCalls(int $campaignId): int
    {
        try {
            $dialerRedis = Redis::connection('dialer');

            return max(0, (int) $dialerRedis->get("dialer:cac:{$campaignId}:active"));
        } catch (\Exception $e) {
            Log::warning('Failed to get worker active calls from Redis', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Set worker active calls in Redis.
     *
     * @param  int  $campaignId  The campaign ID
     * @param  int  $value  The value to set
     */
    private function setWorkerActiveCalls(int $campaignId, int $value): void
    {
        try {
            $dialerRedis = Redis::connection('dialer');
            $dialerRedis->set("dialer:cac:{$campaignId}:active", $value);
        } catch (\Exception $e) {
            Log::error('Failed to set worker active calls in Redis', [
                'campaign_id' => $campaignId,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get active session details from the database.
     *
     * @param  int  $campaignId  The campaign ID
     * @param  array<int, string>  $sessionTokens  The session tokens to look up
     * @return array<int, array<string, mixed>>
     */
    private function getActiveSessionDetails(int $campaignId, array $sessionTokens): array
    {
        if (empty($sessionTokens)) {
            return [];
        }

        $sessions = AutoDialerCallSession::whereIn('session_token', $sessionTokens)
            ->with('destination')
            ->where('campaign_id', $campaignId)
            ->get();

        return $sessions->map(fn ($session) => [
            'session_id' => $session->id,
            'session_token' => $session->session_token,
            'destination_id' => $session->destination_id,
            'phone_number' => $session->destination?->phone_number ?? 'unknown',
            'started_at' => $session->started_at?->toIso8601String(),
            'duration_seconds' => $session->started_at ? now()->diffInSeconds($session->started_at) : 0,
        ])->toArray();
    }

    /**
     * Build monitor info for a single campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to build info for
     * @return array<string, mixed>
     */
    private function buildCampaignMonitorInfo(AutoDialerCampaign $campaign): array
    {
        $activeCalls = $this->getWorkerActiveCalls($campaign->id);

        // Force 0 for paused campaigns
        if ($campaign->status === CampaignStatus::PAUSED && $activeCalls > 0) {
            $this->setWorkerActiveCalls($campaign->id, 0);
            $activeCalls = 0;
        }

        $cac = $campaign->concurrent_active_calls;
        $stats = $this->getDestinationStatistics($campaign);
        $processed = $stats['completed'] + $stats['failed'];

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'progress_percentage' => $stats['total'] > 0
                ? (int) round(($processed / $stats['total']) * 100)
                : 0,
            'total_destinations' => $stats['total'],
            'completed_calls' => $stats['completed'],
            'failed_calls' => $stats['failed'],
            'pending_calls' => $stats['pending'],
            'dialing_calls' => $stats['dialing'],
            'concurrent_active_calls' => $cac,
            'active_calls' => $activeCalls,
            'cac_utilization' => $this->calculateUtilization($activeCalls, $cac),
            'rate_limit_status' => [
                'is_rate_limited' => $campaign->status === CampaignStatus::PAUSED &&
                    $campaign->pause_reason === 'cloudonix_rate_limit',
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

    /**
     * Get destination statistics for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get stats for
     * @return array<string, int>
     */
    private function getDestinationStatistics(AutoDialerCampaign $campaign): array
    {
        return OrganizationScope::bypass(function () use ($campaign): array {
            $listIds = AutoDialerList::where('campaign_id', $campaign->id)->pluck('id');

            if ($listIds->isEmpty()) {
                return ['total' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'dialing' => 0];
            }

            // Clean up stale dialing records
            $this->cleanupStaleDialing($campaign, $listIds);

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
    }

    /**
     * Clean up stale dialing destinations.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @param  \Illuminate\Support\Collection  $listIds  The list IDs
     */
    private function cleanupStaleDialing(AutoDialerCampaign $campaign, $listIds): void
    {
        $buildQuery = function () use ($campaign, $listIds) {
            $query = AutoDialerDestination::whereIn('list_id', $listIds)
                ->where('status', DestinationStatus::DIALING);

            // For active campaigns, only clean up records stuck for > 5 minutes
            if ($campaign->status !== CampaignStatus::PAUSED) {
                $query->where('last_dialed_at', '<', now()->subMinutes(5));
            }

            return $query;
        };

        // Mark as failed if max dial attempts have been reached
        $buildQuery()
            ->where('dial_attempts', '>=', $campaign->max_dial_attempts)
            ->update(['status' => DestinationStatus::FAILED]);

        // Reset remaining stale destinations to pending for retry
        $buildQuery()
            ->where('dial_attempts', '<', $campaign->max_dial_attempts)
            ->update(['status' => DestinationStatus::PENDING]);
    }

    /**
     * Get disposition breakdown for a campaign.
     *
     * @param  int  $campaignId  The campaign ID
     * @return array<string, int>
     */
    private function getDispositions(int $campaignId): array
    {
        return OrganizationScope::bypass(function () use ($campaignId): array {
            $results = AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereNotNull('disposition')
                ->selectRaw('disposition, COUNT(*) as count')
                ->groupBy('disposition')
                ->pluck('count', 'disposition')
                ->toArray();

            $dispositions = self::DEFAULT_DISPOSITIONS;

            foreach ($results as $disposition => $count) {
                $normalized = self::DISPOSITION_MAP[strtolower($disposition)] ?? strtolower($disposition);
                if (array_key_exists($normalized, $dispositions)) {
                    $dispositions[$normalized] += (int) $count;
                }
            }

            return $dispositions;
        });
    }

    /**
     * Get average call duration for a campaign.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function getAverageDuration(int $campaignId): int
    {
        return OrganizationScope::bypass(function () use ($campaignId): int {
            $avg = AutoDialerCallSession::where('campaign_id', $campaignId)
                ->where('status', 'completed')
                ->where('duration', '>', 0)
                ->avg('duration');

            return $avg ? (int) round((float) $avg) : 0;
        });
    }

    /**
     * Get average billable seconds for a campaign.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function getAverageBillsec(int $campaignId): int
    {
        return OrganizationScope::bypass(function () use ($campaignId): int {
            $avg = AutoDialerCallSession::where('campaign_id', $campaignId)
                ->where('status', 'completed')
                ->where('billsec', '>', 0)
                ->avg('billsec');

            return $avg ? (int) round((float) $avg) : 0;
        });
    }

    /**
     * Get active sessions for a campaign (never cached).
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return array<int, array<string, mixed>>
     */
    private function getActiveSessions(AutoDialerCampaign $campaign): array
    {
        // Paused campaigns have no active calls - clean up stale sessions
        if ($campaign->status === CampaignStatus::PAUSED) {
            $this->cleanupStaleSessions($campaign->id);

            return [];
        }

        return OrganizationScope::bypass(function () use ($campaign): array {
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

    /**
     * Clean up stale active sessions for a campaign.
     *
     * @param  int  $campaignId  The campaign ID
     */
    private function cleanupStaleSessions(int $campaignId): void
    {
        OrganizationScope::bypass(function () use ($campaignId): void {
            AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereIn('status', ['initiated', 'ringing', 'answered'])
                ->update([
                    'status' => 'failed',
                    'disposition' => 'cancelled',
                    'completed_at' => now(),
                ]);
        });
    }

    /**
     * Calculate utilization percentage.
     *
     * @param  int  $active  Active count
     * @param  int  $limit  Limit (CAC)
     */
    private function calculateUtilization(int $active, int $limit): float
    {
        return $limit > 0 ? round(($active / $limit) * 100, 1) : 0;
    }

    /**
     * Get rate limit status for a campaign.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign
     * @return array<string, mixed>
     */
    private function getRateLimitStatus(AutoDialerCampaign $campaign): array
    {
        $isRateLimited = $campaign->status === CampaignStatus::PAUSED &&
            $campaign->pause_reason === 'cloudonix_rate_limit';

        return [
            'is_rate_limited' => $isRateLimited,
            'pause_reason' => $campaign->pause_reason,
            'resumes_at' => $campaign->resume_at?->toIso8601String(),
            'can_resume_now' => $campaign->resume_at ? now()->gte($campaign->resume_at) : true,
        ];
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
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(5)->get($healthUrl);

            if ($response->ok()) {
                /** @var array<string, mixed> $data */
                $data = $response->json() ?? [];

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
}
