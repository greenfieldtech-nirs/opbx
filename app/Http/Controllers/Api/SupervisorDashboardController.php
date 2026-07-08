<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallDetailRecord;
use App\Models\SessionUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class SupervisorDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->role->canViewAssignedDashboard()) {
            abort(403);
        }

        if ($user->isSupervisor()) {
            $assignedUsers = $user->supervisedUsers;
            $assignedRingGroups = $user->supervisedRingGroups;
        } else {
            $assignedUsers = $user->organization->users()->where('role', '!=', 'supervisor')->get();
            $assignedRingGroups = $user->organization->ringGroups()->get();
        }

        $assignedUserIds = $assignedUsers->pluck('id');
        $assignedRingGroupIds = $assignedRingGroups->pluck('id');

        $activeCalls = $this->getActiveCallsForSupervisor($assignedUserIds, $assignedRingGroupIds);
        $recentCalls = $this->getRecentCallsForSupervisor($assignedUserIds, $assignedRingGroupIds);

        return response()->json([
            'assigned_users_count' => $assignedUsers->count(),
            'assigned_ring_groups_count' => $assignedRingGroups->count(),
            'active_calls_count' => $activeCalls->count(),
            'recent_calls' => $recentCalls,
            'assigned_users' => $assignedUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'extension_number' => $u->extension?->extension_number]),
            'assigned_ring_groups' => $assignedRingGroups->map(fn ($rg) => ['id' => $rg->id, 'name' => $rg->name]),
        ]);
    }

    private function getActiveCallsForSupervisor($assignedUserIds, $assignedRingGroupIds)
    {
        // Reuse the Supervisor filter logic from SessionUpdateController
        return SessionUpdate::query()
            ->when($assignedUserIds->isNotEmpty(), fn ($q) => $q->whereIn('caller_id', $assignedUserIds->toArray()))
            ->orWhere(fn ($q) => $q->whereIn('destination', $assignedRingGroupIds->toArray()))
            ->whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->where('session_modified_at', '>=', now()->subMinutes(30))
            ->get();
    }

    private function getRecentCallsForSupervisor($assignedUserIds, $assignedRingGroupIds)
    {
        return CallDetailRecord::query()
            ->when($assignedUserIds->isNotEmpty(), fn ($q) => $q->whereIn('from', $assignedUserIds->toArray()))
            ->orWhere(fn ($q) => $q->whereIn('to', $assignedUserIds->toArray()))
            ->orderByDesc('session_timestamp')
            ->limit(5)
            ->get();
    }
}
