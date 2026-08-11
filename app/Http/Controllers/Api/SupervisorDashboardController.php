<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallDetailRecordResource;
use App\Models\CallDetailRecord;
use App\Services\ActiveCallsService;
use App\Services\Supervisor\SupervisorFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class SupervisorDashboardController extends Controller
{
    public function __invoke(SupervisorFilterService $filterService, ActiveCallsService $activeCallsService): JsonResponse
    {
        $user = Auth::user();

        if (! $user->role->canViewAssignedDashboard()) {
            abort(403);
        }

        $isSupervisor = $user->isSupervisor();

        if ($isSupervisor) {
            $assignedUsers = $user->supervisedUsers;
            $assignedRingGroups = $user->supervisedRingGroups;
            $identifiers = $filterService->resourceIdentifiers($user);
        } else {
            $assignedUsers = $user->organization->users()->where('role', '!=', 'supervisor')->get();
            $assignedRingGroups = $user->organization->ringGroups()->get();
            $identifiers = null;
        }

        $activeCalls = $activeCallsService->forOrganization($user->organization_id, $identifiers);
        $recentCalls = $this->getRecentCallsForSupervisor($identifiers);

        return response()->json([
            'assigned_users_count' => $assignedUsers->count(),
            'assigned_ring_groups_count' => $assignedRingGroups->count(),
            'active_calls_count' => $activeCalls->count(),
            'recent_calls' => CallDetailRecordResource::collection($recentCalls),
            'assigned_users' => $assignedUsers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'extension_number' => $u->extension?->extension_number]),
            'assigned_ring_groups' => $assignedRingGroups->map(fn ($rg) => ['id' => $rg->id, 'name' => $rg->name]),
        ]);
    }

    private function getRecentCallsForSupervisor(?array $identifiers)
    {
        $query = CallDetailRecord::query()
            ->with(['extension.user:id,name', 'sessionUpdate'])
            ->orderByDesc('session_timestamp')
            ->limit(5);

        if ($identifiers !== null) {
            if (count($identifiers) === 0) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($identifiers): void {
                    $q->whereIn('from', $identifiers)
                        ->orWhereIn('to', $identifiers);
                });
            }
        }

        return $query->get();
    }
}
