<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallDetailRecord;
use App\Models\SessionUpdate;
use App\Services\Supervisor\SupervisorFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class SupervisorDashboardController extends Controller
{
    public function __invoke(SupervisorFilterService $filterService): JsonResponse
    {
        $user = Auth::user();

        if (! $user->role->canViewAssignedDashboard()) {
            abort(403);
        }

        if ($user->isSupervisor()) {
            $assignedUsers = $user->supervisedUsers;
            $assignedRingGroups = $user->supervisedRingGroups;
            $identifiers = $filterService->resourceIdentifiers($user);
        } else {
            $assignedUsers = $user->organization->users()->where('role', '!=', 'supervisor')->get();
            $assignedRingGroups = $user->organization->ringGroups()->get();
            $identifiers = [];
        }

        $activeCalls = $this->getActiveCallsForSupervisor($identifiers);
        $recentCalls = $this->getRecentCallsForSupervisor($identifiers);

        return response()->json([
            'assigned_users_count' => $assignedUsers->count(),
            'assigned_ring_groups_count' => $assignedRingGroups->count(),
            'active_calls_count' => $activeCalls->count(),
            'recent_calls' => $recentCalls,
            'assigned_users' => $assignedUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'extension_number' => $u->extension?->extension_number]),
            'assigned_ring_groups' => $assignedRingGroups->map(fn ($rg) => ['id' => $rg->id, 'name' => $rg->name]),
        ]);
    }

    private function getActiveCallsForSupervisor(array $identifiers)
    {
        $query = SessionUpdate::query()
            ->whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->where('session_modified_at', '>=', now()->subMinutes(30));

        if (count($identifiers) > 0) {
            $query->where(function ($q) use ($identifiers): void {
                $q->whereIn('caller_id', $identifiers)
                    ->orWhereIn('destination', $identifiers);
            });
        }

        return $query->get();
    }

    private function getRecentCallsForSupervisor(array $identifiers)
    {
        $query = CallDetailRecord::query()->orderByDesc('session_timestamp')->limit(5);

        if (count($identifiers) > 0) {
            $query->where(function ($q) use ($identifiers): void {
                $q->whereIn('from', $identifiers)
                    ->orWhereIn('to', $identifiers);
            });
        }

        return $query->get();
    }
}
