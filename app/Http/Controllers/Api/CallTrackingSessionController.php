<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\SessionIndexRequest;
use App\Http\Resources\CallTrackingSessionResource;
use App\Models\CallTrackingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Call Tracking Session API controller.
 */
class CallTrackingSessionController extends Controller
{
    private const int DEFAULT_PER_PAGE = 20;

    private const int MAX_PER_PAGE = 100;

    /**
     * Display a paginated list of call tracking sessions.
     */
    public function index(SessionIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $query = CallTrackingSession::query()
            ->forOrganization($user->organization_id)
            ->with(['campaign', 'did']);

        $this->applyFilters($query, $validated);

        $query->orderByDesc('started_at');

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $sessions = $query->paginate($perPage);

        return response()->json([
            'data' => CallTrackingSessionResource::collection($sessions)->resolve(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
                'from' => $sessions->firstItem(),
                'to' => $sessions->lastItem(),
            ],
        ]);
    }

    /**
     * Apply filters from validated request data.
     *
     * @param  Builder  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyFilters($query, array $validated): void
    {
        if (! empty($validated['campaign_ids'])) {
            $query->whereIn('call_tracking_campaign_id', $validated['campaign_ids']);
        }

        if (! empty($validated['sources'])) {
            $query->whereIn('source', $validated['sources']);
        }

        if (! empty($validated['mediums'])) {
            $query->whereIn('medium', $validated['mediums']);
        }

        if (! empty($validated['start_date'])) {
            $query->whereDate('started_at', '>=', $validated['start_date']);
        }

        if (! empty($validated['end_date'])) {
            $query->whereDate('started_at', '<=', $validated['end_date']);
        }

        if (isset($validated['is_converted'])) {
            $query->where('is_converted', $validated['is_converted']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('caller_number', 'like', '%'.$search.'%')
                    ->orWhere('called_number', 'like', '%'.$search.'%');
            });
        }
    }
}
