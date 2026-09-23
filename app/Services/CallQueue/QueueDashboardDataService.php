<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * Shared dashboard data for the internal and public queues dashboards:
 * live snapshots, timespan aggregates, rolling counters.
 */
class QueueDashboardDataService
{
    private const ROLLING_WINDOWS = [
        '15m' => 15,
        '30m' => 30,
        '60m' => 60,
        '24h' => 1440,
    ];

    public function __construct(
        private readonly AcdWorkerClient $worker,
    ) {}

    /**
     * Live payload for one queue (worker snapshot enriched with caller context).
     *
     * @return array<string, mixed>
     */
    public function livePayload(CallQueue $callQueue): array
    {
        $live = $this->worker->live($callQueue->organization_id, $callQueue->id, $this->roster($callQueue));

        return [
            'call_queue_id' => $callQueue->id,
            'call_queue_name' => $callQueue->name,
            'waiting' => $this->enrichWaiting($callQueue, $live['waiting'] ?? []),
            'agents' => $live['agents'] ?? [],
            'rolling' => $this->rollingCounts($callQueue),
            'waiting_time_avg_seconds' => $this->averageWaitingTime($callQueue),
        ];
    }

    /**
     * History statistics payload for one queue over a date range.
     *
     * @return array<string, mixed>
     */
    public function statsPayload(CallQueue $callQueue, ?\Illuminate\Support\Carbon $from, ?\Illuminate\Support\Carbon $to): array
    {
        $from ??= now()->subDay();
        $to ??= now();

        $base = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->whereBetween('entered_at', [$from, $to]);

        $answered = (clone $base)->where('disposition', 'answered');
        $abandoned = (clone $base)->where('disposition', 'abandoned');
        $overflowed = (clone $base)->where('disposition', 'overflow');

        return [
            'call_queue_id' => $callQueue->id,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'waiting_time' => $this->durationStats(clone $answered, 'waiting_seconds'),
            'handling_time' => $this->durationStats(clone $answered, 'handling_seconds'),
            'totals' => [
                'handled' => (clone $answered)->count(),
                'abandoned' => (clone $abandoned)->count(),
                'overflowed' => (clone $overflowed)->count(),
            ],
        ];
    }

    /**
     * Compact queue list for dashboards.
     *
     * @return Collection<int, CallQueue>
     */
    public function queuesFor(int $organizationId): Collection
    {
        return CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, array{userId: int, extensionNumber: string}>
     */
    public function roster(CallQueue $callQueue): array
    {
        return $callQueue->agentRecords()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with(['user.extension' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->get()
            ->filter(fn ($membership) => $membership->user?->extension !== null)
            ->map(fn ($membership) => [
                'userId' => $membership->user_id,
                'extensionNumber' => $membership->user->extension->extension_number,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $waiting
     * @return array<int, array<string, mixed>>
     */
    private function enrichWaiting(CallQueue $callQueue, array $waiting): array
    {
        if (empty($waiting)) {
            return [];
        }

        $rows = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->whereIn('call_id', collect($waiting)->pluck('callId')->all())
            ->get()
            ->keyBy('call_id');

        return array_map(function (array $entry) use ($rows) {
            $row = $rows->get($entry['callId'] ?? '');

            return array_merge($entry, [
                'from_number' => $row?->from_number,
                'to_number' => $row?->to_number,
                'entered_at' => $row?->entered_at?->toIso8601String(),
            ]);
        }, $waiting);
    }

    /**
     * @return array<string, int>
     */
    private function rollingCounts(CallQueue $callQueue): array
    {
        $counts = [];

        foreach (self::ROLLING_WINDOWS as $label => $minutes) {
            $since = now()->subMinutes($minutes);

            $counts["handled_{$label}"] = QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('call_queue_id', $callQueue->id)
                ->where('disposition', 'answered')
                ->where('entered_at', '>=', $since)
                ->count();

            $counts["abandoned_{$label}"] = QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('call_queue_id', $callQueue->id)
                ->where('disposition', 'abandoned')
                ->where('entered_at', '>=', $since)
                ->count();
        }

        return $counts;
    }

    private function averageWaitingTime(CallQueue $callQueue): ?float
    {
        $avg = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('disposition', 'answered')
            ->where('entered_at', '>=', now()->subDay())
            ->avg('waiting_seconds');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * @return array{min: int|null, max: int|null, avg: float|null, stddev: float|null}
     */
    private function durationStats(\Illuminate\Database\Eloquent\Builder $query, string $column): array
    {
        $row = $query->selectRaw("MIN({$column}) as min_val, MAX({$column}) as max_val, AVG({$column}) as avg_val, STDDEV({$column}) as stddev_val")
            ->first();

        return [
            'min' => $row->min_val !== null ? (int) $row->min_val : null,
            'max' => $row->max_val !== null ? (int) $row->max_val : null,
            'avg' => $row->avg_val !== null ? round((float) $row->avg_val, 2) : null,
            'stddev' => $row->stddev_val !== null ? round((float) $row->stddev_val, 2) : null,
        ];
    }
}
