<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\GrantableResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Requests\ApiKey\UpdateApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $apiKeys) {}

    public function grantableResources(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApiKey::class);

        return response()->json(['data' => GrantableResource::slugs()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApiKey::class);

        $keys = ApiKey::with('permissions')
            ->where('organization_id', $request->user()->organization_id)
            ->latest()
            ->get();

        return ApiKeyResource::collection($keys)->response();
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$apiKey, $plaintext] = $this->apiKeys->create(
            organizationId: $request->user()->organization_id,
            name: $request->string('name')->toString(),
            permissions: $request->input('permissions'),
            createdBy: $request->user()->id,
        );

        return response()->json([
            'data' => new ApiKeyResource($apiKey),
            'key' => $plaintext,
        ], 201);
    }

    public function show(ApiKey $apiKey): JsonResponse
    {
        $this->authorize('view', $apiKey);

        return (new ApiKeyResource($apiKey->load('permissions')))->response();
    }

    public function update(UpdateApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        if ($request->has('name')) {
            $apiKey->update(['name' => $request->string('name')->toString()]);
        }

        if ($request->has('permissions')) {
            $this->apiKeys->replacePermissions($apiKey, $request->input('permissions'));
        }

        return (new ApiKeyResource($apiKey->fresh('permissions')))->response();
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $this->authorize('delete', $apiKey);

        $apiKey->update(['revoked_at' => now()]);

        return response()->json(['message' => 'API key revoked.']);
    }
}
