<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Call log API controller (read-only).
 */
class CallLogController extends Controller
    use ApiRequestHandler;{
    /**
     * List call logs for the authenticated user's organization.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CallLog::with(['didNumber', 'extension', 'ringGroup'])
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        if ($request->has('from_number')) {
            $query->where('from_number', 'like', '%' . $request->input('from_number') . '%');
        }

        if ($request->has('to_number')) {
            $query->where('to_number', 'like', '%' . $request->input('to_number') . '%');
        }

        $callLogs = $query->paginate(50);

        return response()->json($callLogs);
    }

    /**
     * Get a specific call log.
     */
    public function show(CallLog $callLog): JsonResponse
    {
        $callLog->load(['didNumber', 'extension', 'ringGroup']);

        return response()->json($callLog);
    }

    /**
     * Get active calls for the organization.
     */
    public function active(Request $request): JsonResponse
    {
        $activeCalls = CallLog::whereIn('status', ['initiated', 'ringing', 'answered'])
            ->with(['didNumber', 'extension'])
            ->orderBy('initiated_at', 'desc')
            ->get();

        return response()->json($activeCalls);
    }

    /**
     * Get call statistics for the organization.
     */
    public function statistics(Request $request): JsonResponse
    {
        $fromDate = $request->input('from_date', now()->subDays(30)->toDateString());
        $toDate = $request->input('to_date', now()->toDateString());

        $stats = [
            'total_calls' => CallLog::whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)
                ->count(),
            'answered_calls' => CallLog::where('status', 'answered')
                ->orWhere('status', 'completed')
                ->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)
                ->count(),
            'missed_calls' => CallLog::whereIn('status', ['no_answer', 'busy', 'failed'])
                ->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)
                ->count(),
            'average_duration' => CallLog::where('status', 'completed')
                ->whereNotNull('duration')
                ->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)
                ->avg('duration'),
            'active_calls' => CallLog::whereIn('status', ['initiated', 'ringing', 'answered'])
                ->count(),
        ];

        return response()->json($stats);
    }
}
