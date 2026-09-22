<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Call queue statistics: history aggregates from queue_calls and the
 * live snapshot proxied from the acd-worker.
 */
class QueueStatsController extends Controller
{
    private const ROLLING_WINDOWS = [
        '15m' => 15,
        '30m' => 30,
        '60m' => 60,
        '24h' => 1440,
    ];

    /**
     * History statistics for a queue over an optional date range.
     *
     * Defaults to the last 24 hours when no range is given.
     */
    public function stats(Request $request, CallQueue $callQueue): JsonResponse
    {
        $this->authorize('view', $callQueue);

        $from = $request->date('from') ?? now()->subDay();
        $to = $request->date('to') ?? now();

        $base = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->whereBetween('entered_at', [$from, $to]);

        $answered = (clone $base)->where('disposition', 'answered');
        $abandoned = (clone $base)->where('disposition', 'abandoned');
        $overflowed = (clone $base)->where('disposition', 'overflow');

        return response()->json([
            'data' => [
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
            ],
        ]);
    }

    /**
     * Live snapshot: waiting callers with positions, agent states (from the
     * acd-worker), and rolling handled/abandoned counters (from MySQL).
     */
    public function live(Request $request, CallQueue $callQueue): JsonResponse
    {
        $this->authorize('view', $callQueue);

        $worker = app(AcdWorkerClient::class);
        $live = $worker->live($callQueue->organization_id, $callQueue->id, $this->roster($callQueue));

        return response()->json([
            'data' => [
                'call_queue_id' => $callQueue->id,
                'call_queue_name' => $callQueue->name,
                'waiting' => $this->enrichWaiting($callQueue, $live['waiting'] ?? []),
                'agents' => $live['agents'] ?? [],
                'rolling' => $this->rollingCounts($callQueue),
                'waiting_time_avg_seconds' => $this->averageWaitingTime($callQueue),
            ],
        ]);
    }

    /**
     * Join worker waiting entries with queue_calls for caller context.
     *
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
     * Average waiting time (answered calls, last 24 hours) for this queue.
     */
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

    /**
     * @return array<int, array{userId: int, extensionNumber: string}>
     */
    private function roster(CallQueue $callQueue): array
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
}
