<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sentry\StoreSentryBlacklistRequest;
use App\Http\Requests\Sentry\UpdateSentryBlacklistRequest;
use App\Models\SentryBlacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SentryBlacklistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $blacklists = SentryBlacklist::where('organization_id', $request->user()->organization_id)
            ->withCount('items')
            ->get();

        return response()->json(['data' => $blacklists]);
    }

    public function store(StoreSentryBlacklistRequest $request): JsonResponse
    {
        $blacklist = SentryBlacklist::create(array_merge(
            $request->validated(),
            ['organization_id' => $request->user()->organization_id]
        ));

        return response()->json(['data' => $blacklist], 201);
    }

    public function show(Request $request, SentryBlacklist $blacklist): JsonResponse
    {
        if ($blacklist->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $blacklist->load('items');

        return response()->json(['data' => $blacklist]);
    }

    public function update(UpdateSentryBlacklistRequest $request, SentryBlacklist $blacklist): JsonResponse
    {
        if ($blacklist->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $blacklist->update($request->validated());

        return response()->json(['data' => $blacklist]);
    }

    public function destroy(Request $request, SentryBlacklist $blacklist): JsonResponse
    {
        if ($blacklist->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $blacklist->delete();

        return response()->json(null, 204);
    }
}
