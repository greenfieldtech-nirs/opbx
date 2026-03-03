<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
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
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Default sort: newest first
        $query->orderBy('created_at', 'desc');

        $perPage = $request->input('per_page', 25);
        $logs = $query->paginate(min($perPage, 100));

        return response()->json($logs);
    }
}
