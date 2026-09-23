<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Services\CallQueue\QueueDashboardDataService;
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

        return response()->json([
            'data' => app(QueueDashboardDataService::class)->statsPayload(
                $callQueue,
                $request->date('from'),
                $request->date('to')
            ),
        ]);
    }

    /**
     * Live snapshot: waiting callers with positions, agent states (from the
     * acd-worker), and rolling handled/abandoned counters (from MySQL).
     */
    public function live(Request $request, CallQueue $callQueue): JsonResponse
    {
        $this->authorize('view', $callQueue);

        return response()->json([
            'data' => app(QueueDashboardDataService::class)->livePayload($callQueue),
        ]);
    }

}
