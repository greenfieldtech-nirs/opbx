<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;


use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\ConferenceRoom\StoreConferenceRoomRequest;
use App\Models\SentryBlacklist;
use App\Models\SentryBlacklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentryBlacklistItemController extends Controller
{
    use ApiRequestHandler;
    public function store(StoreSentryBlacklistItemRequest $request, SentryBlacklist $blacklist): JsonResponse
    {
        if ($blacklist->organization_id !== $this->getAuthenticatedUser($request)->organization_id) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $validated = $request->validated();
        $validated['created_by'] = $this->getAuthenticatedUser($request)->id;

        // Handle empty expires_at if it was passed as an empty string
        if (isset($validated['expires_at']) && empty($validated['expires_at'])) {
            unset($validated['expires_at']);
        }

        $item = $blacklist->items()->create($validated);
        $item->load('creator:id,name');

        return response()->json(['data' => $item], 201);
    }

    public function destroy(Request $request, SentryBlacklist $blacklist, SentryBlacklistItem $item): JsonResponse
    {
        if ($blacklist->organization_id !== $this->getAuthenticatedUser($request)->organization_id || $item->blacklist_id !== $blacklist->id) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $item->delete();

        return response()->json(null, 204);
    }
}
