<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallDetailRecordResource;
use App\Models\CallDetailRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

/**
 * Call Detail Record (CDR) API controller (read-only).
 *
 * Provides access to CDR data with filtering and pagination.
 */
class CallDetailRecordController extends Controller
{
    use ApiRequestHandler;
    /**
     * List CDRs for the authenticated user's organization.
     *
     * Supports filtering by:
     * - from: Caller number (partial match)
     * - to: Called number (partial match)
     * - from_date: Start date filter (inclusive)
     * - to_date: End date filter (inclusive)
     * - disposition: Call disposition
     *
     * Returns paginated results (50 per page by default).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CallDetailRecord::query()
            ->orderBy('session_timestamp', 'desc');

        // Filter by caller number (from)
        if ($request->has('from') && $request->input('from') !== '') {
            $query->where('from', 'like', '%' . $request->input('from') . '%');
        }

        // Filter by called number (to)
        if ($request->has('to') && $request->input('to') !== '') {
            $query->where('to', 'like', '%' . $request->input('to') . '%');
        }

        // Filter by disposition
        if ($request->has('disposition') && $request->input('disposition') !== '') {
            $query->where('disposition', $request->input('disposition'));
        }

        // Filter by date range
        if ($request->has('from_date') && $request->input('from_date') !== '') {
            $query->whereDate('session_timestamp', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date') && $request->input('to_date') !== '') {
            $query->whereDate('session_timestamp', '<=', $request->input('to_date'));
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $perPage = min(max((int) $perPage, 10), 100); // Between 10 and 100

        $cdrs = $query->paginate($perPage);

        return CallDetailRecordResource::collection($cdrs);
    }

    /**
     * Get a specific CDR by ID.
     */
    public function show(CallDetailRecord $callDetailRecord): CallDetailRecordResource
    {
        return new CallDetailRecordResource($callDetailRecord);
    }

    /**
     * Get CDR statistics for the organization.
     */
    public function statistics(Request $request): JsonResponse
    {
        $fromDate = $request->input('from_date', now()->subDays(30)->toDateString());
        $toDate = $request->input('to_date', now()->toDateString());

        $stats = [
            'total_calls' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->count(),
            'total_duration' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('duration'),
            'total_billsec' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('billsec'),
            'average_duration' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->where('duration', '>', 0)
                ->avg('duration'),
            'total_cost' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('sell_cost'),
            'by_disposition' => CallDetailRecord::whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->selectRaw('disposition, COUNT(*) as count')
                ->groupBy('disposition')
                ->pluck('count', 'disposition'),
        ];

        return response()->json($stats);
    }
}
