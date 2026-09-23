<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\QueueDashboardDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (unauthenticated) queues dashboard API, keyed by the organization's
 * unguessable public_queues_token. Intended for call-center wallboards.
 * Rate-limited at the route level.
 */
class PublicQueuesDashboardController extends Controller
{
    public function queues(string $token): JsonResponse
    {
        $organization = $this->organizationFromToken($token);

        if (! $organization) {
            return response()->json(['error' => 'Invalid dashboard link.'], 404);
        }

        $queues = app(QueueDashboardDataService::class)
            ->queuesFor($organization->id)
            ->map(fn (CallQueue $q) => [
                'id' => $q->id,
                'name' => $q->name,
                'strategy' => $q->strategy->value,
                'status' => $q->status->value,
            ])
            ->values();

        return response()->json(['data' => $queues]);
    }

    public function live(string $token, int $queue): JsonResponse
    {
        $callQueue = $this->queueFromToken($token, $queue);

        if (! $callQueue) {
            return response()->json(['error' => 'Invalid dashboard link.'], 404);
        }

        return response()->json([
            'data' => app(QueueDashboardDataService::class)->livePayload($callQueue),
        ]);
    }

    public function stats(string $token, int $queue, Request $request): JsonResponse
    {
        $callQueue = $this->queueFromToken($token, $queue);

        if (! $callQueue) {
            return response()->json(['error' => 'Invalid dashboard link.'], 404);
        }

        return response()->json([
            'data' => app(QueueDashboardDataService::class)->statsPayload(
                $callQueue,
                $request->date('from'),
                $request->date('to')
            ),
        ]);
    }

    private function queueFromToken(string $token, int $queueId): ?CallQueue
    {
        $organization = $this->organizationFromToken($token);

        if (! $organization) {
            return null;
        }

        return CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $queueId)
            ->where('organization_id', $organization->id)
            ->first();
    }

    private function organizationFromToken(string $token): ?Organization
    {
        if ($token === '') {
            return null;
        }

        return Organization::withoutGlobalScope(OrganizationScope::class)
            ->where('public_queues_token', $token)
            ->first();
    }
}
