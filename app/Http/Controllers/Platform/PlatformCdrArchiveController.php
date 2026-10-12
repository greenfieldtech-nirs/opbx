<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallDetailRecordResource;
use App\Models\ArchivedCallDetailRecord;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Warm-tier CDRs (6-12 months old) across organisations, for the platform owner only.
 */
class PlatformCdrArchiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['nullable', 'string', 'max:100'],
            'disposition' => ['nullable', 'string', 'max:50'],
        ]);

        $query = ArchivedCallDetailRecord::query()
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('disposition'), fn ($q) => $q->where('disposition', $request->input('disposition')))
            ->when($request->filled('from'), fn ($q) => $q->where('from', 'like', addcslashes(ltrim((string) $request->input('from'), '+'), '%_\\').'%'))
            ->when($request->filled('to'), fn ($q) => $q->where('to', 'like', addcslashes(ltrim((string) $request->input('to'), '+'), '%_\\').'%'))
            ->when($request->filled('from_date'), fn ($q) => $q->where('session_timestamp', '>=', Carbon::parse($request->input('from_date'))->startOfDay()))
            ->when($request->filled('to_date'), fn ($q) => $q->where('session_timestamp', '<=', Carbon::parse($request->input('to_date'))->endOfDay()))
            ->orderByDesc('session_timestamp');

        $paginator = $query->paginate(min($this->perPage($request, 25), 100));

        return response()->json([
            'data' => CallDetailRecordResource::collection($paginator)->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
