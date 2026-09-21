<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\Extension;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\QueueAgentStateService;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * *45{queue_id} dial-in agent login/logout.
 *
 * A queue member dials *45 followed by the queue ID (shown on the Call Queues
 * page) to toggle their agent state for that queue: logged out -> available,
 * anything else -> logged out. Confirmation is spoken in the queue's language.
 */
class QueueAgentDialController extends Controller
{
    public const FEATURE_CODE_PREFIX = '*45';

    public static function dialCode(CallQueue $callQueue): string
    {
        return self::FEATURE_CODE_PREFIX.$callQueue->id;
    }

    public function handle(Request $request, int $queueId): Response
    {
        $organizationId = (int) $request->input('_organization_id');
        $from = (string) $request->input('From');

        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('extension_number', $from)
            ->first();

        if (! $extension || ! $extension->user_id) {
            return $this->respond($request, 'Your extension is not recognized.');
        }

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $queueId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $callQueue) {
            return $this->respond($request, "Queue {$queueId} was not found.");
        }

        if (! $callQueue->isActive()) {
            return $this->respond($request, "Queue {$callQueue->name} is currently inactive.");
        }

        $userId = (int) $extension->user_id;

        $isAgent = CallQueueAgent::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isAgent) {
            return $this->respond($request, "You are not an agent of queue {$callQueue->name}.", $callQueue);
        }

        $stateService = app(QueueAgentStateService::class);
        $currentState = $stateService->currentState($callQueue, $userId, $extension->extension_number);

        // Toggle: anything but logged out -> log out; logged out -> log in.
        $newState = $currentState === 'LOGGED_OUT' ? 'available' : 'logged_out';
        $stateService->setState($callQueue, $userId, $newState);

        Log::info('QueueAgentDialController: Agent state toggled via dial-in', [
            'call_queue_id' => $callQueue->id,
            'user_id' => $userId,
            'from' => $from,
            'previous_state' => $currentState,
            'new_state' => $newState,
        ]);

        return $this->respond(
            $request,
            $newState === 'available'
                ? "You are now logged in to queue {$callQueue->name}."
                : "You have logged out of queue {$callQueue->name}.",
            $callQueue
        );
    }

    private function respond(Request $request, string $text, ?CallQueue $callQueue = null): Response
    {
        $builder = new CxmlBuilder;
        $builder->say($text, null, $callQueue?->announce_position_language)->hangup();

        return $builder->toResponse();
    }
}
