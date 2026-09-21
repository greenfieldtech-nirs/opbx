<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Queue agent self-service endpoints (state management).
 *
 * Login/logout/status changes are forwarded to the ACD worker; see
 * CallQueueAgentStateController behaviour in Phase 5 of the workplan.
 */
class CallQueueAgentController extends Controller
{
    /**
     * Update the authenticated user's agent state for a queue.
     * (Available / Wrap-up / Logged out)
     */
    public function updateMyState(Request $request, CallQueue $callQueue): JsonResponse
    {
        $request->validate([
            'state' => ['required', 'in:available,wrap_up,logged_out'],
        ]);

        // Phase 5: forward to acd-worker via AcdWorkerClient and audit-log the change.
        abort(501, 'Agent state management is not yet enabled.');
    }
}
