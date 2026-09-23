<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Management of the public queues dashboard deep link (owner/pbx_admin).
 * The token is generated lazily and can be rotated to revoke access.
 */
class QueuesDashboardLinkController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeLinkAccess($request);

        $organization = $request->user()->organization()->firstOrFail();

        if (! $organization->public_queues_token) {
            $organization->public_queues_token = (string) Str::uuid();
            $organization->save();
        }

        return response()->json(['data' => ['url' => $this->buildUrl($organization->public_queues_token)]]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $this->authorizeLinkAccess($request);

        $organization = $request->user()->organization()->firstOrFail();
        $organization->public_queues_token = (string) Str::uuid();
        $organization->save();

        return response()->json(['data' => ['url' => $this->buildUrl($organization->public_queues_token)]]);
    }

    private function authorizeLinkAccess(Request $request): void
    {
        abort_unless($request->user()->isOwner() || $request->user()->isPBXAdmin(), 403);
    }

    private function buildUrl(string $token): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return "{$base}/public/queues-dashboard/{$token}";
    }
}
