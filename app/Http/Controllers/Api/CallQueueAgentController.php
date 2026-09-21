<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\Logging\AuditLogger;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\QueueAgentStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Queue agent self-service endpoints (state management).
 */
class CallQueueAgentController extends Controller
{
    private const VALID_STATES = ['available', 'wrap_up', 'logged_out'];

    /**
     * Update the authenticated user's agent state for a queue.
     * (Available / Wrap-up / Logged out)
     */
    public function updateMyState(Request $request, CallQueue $callQueue): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'in:'.implode(',', self::VALID_STATES)],
        ]);

        $user = $request->user();
        $state = $validated['state'];

        $isAgent = CallQueueAgent::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('user_id', $user->id)
            ->exists();

        $isAdmin = $user->isOwner() || $user->isPBXAdmin();

        if (! $isAgent && ! $isAdmin) {
            abort(403, 'You are not an agent of this queue.');
        }

        // Admin operating on behalf of an agent: target user may be specified.
        $targetUserId = $isAdmin && $request->filled('user_id')
            ? (int) $request->input('user_id')
            : $user->id;

        $accepted = app(QueueAgentStateService::class)->setState($callQueue, $targetUserId, $state);

        if (! $accepted) {
            abort(502, 'Queue engine did not accept the state change. Please try again.');
        }

        AuditLogger::log('call_queue.agent_state_changed', [
            'call_queue_id' => $callQueue->id,
            'user_id' => $targetUserId,
            'state' => $state,
            'changed_by' => $user->id,
        ], request: $request, user: $user);

        return response()->json([
            'message' => 'Agent state updated.',
            'state' => $state,
        ]);
    }

    /**
     * Queues the authenticated user is an agent of, with current live state.
     */
    public function myQueues(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = CallQueueAgent::withoutGlobalScope(OrganizationScope::class)
            ->where('user_id', $user->id)
            ->with(['callQueue' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->get();

        $worker = app(AcdWorkerClient::class);
        $queues = [];

        foreach ($memberships as $membership) {
            $queue = $membership->callQueue;
            if (! $queue) {
                continue;
            }

            $roster = $this->roster($queue);
            $live = $worker->live($queue->organization_id, $queue->id, $roster);

            $myState = collect($live['agents'] ?? [])->firstWhere('userId', (string) $user->id);

            $queues[] = [
                'id' => $queue->id,
                'name' => $queue->name,
                'state' => $myState['state'] ?? 'LOGGED_OUT',
            ];
        }

        return response()->json(['data' => $queues]);
    }

    /**
     * @return array<int, array{userId: int, extensionNumber: string}>
     */
    private function roster(CallQueue $callQueue): array
    {
        return CallQueueAgent::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->with(['user.extension' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->get()
            ->filter(fn ($membership) => $membership->user?->extension !== null)
            ->map(fn ($membership) => [
                'userId' => $membership->user_id,
                'extensionNumber' => $membership->user->extension->extension_number,
            ])
            ->values()
            ->all();
    }
}
