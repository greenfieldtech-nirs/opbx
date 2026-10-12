<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform Audit Log Controller
 *
 * Provides access to platform management audit logs.
 */
class PlatformAuditLogController extends Controller
{
    /**
     * List audit log entries.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);

        $query = PlatformAuditLog::query()
            ->with([
                'platformManager:id,name,email',
                'targetOrganization:id,name,slug',
            ]);

        // Filter by platform manager
        if ($request->filled('platform_manager_user_id')) {
            $query->where('platform_manager_user_id', $request->input('platform_manager_user_id'));
        }

        // Filter by target organization
        if ($request->filled('target_organization_id')) {
            $query->where('target_organization_id', $request->input('target_organization_id'));
        }

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }

        // Default sort: newest first
        $query->orderBy('created_at', 'desc');

        $perPage = $this->perPage($request, 25);
        $paginator = $query->paginate(min($perPage, 100));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
