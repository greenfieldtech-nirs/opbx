<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrunkRequest;
use App\Http\Requests\UpdateTrunkRequest;
use App\Http\Resources\TrunkResource;
use App\Models\CloudonixSettings;
use App\Models\OutboundWhitelist;
use App\Services\CloudonixClient\CloudonixClient;
use App\Services\CloudonixClient\CloudonixTrunksClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trunk management API.
 *
 * Trunks are not local models — they live in Cloudonix. This controller is a
 * thin, authorized proxy over CloudonixTrunksClient with credential masking
 * (TrunkResource) and guards for Cloudonix-managed public-* trunks.
 */
class TrunkController extends Controller
{
    private const PUBLIC_DIRECTIONS = ['public-inbound', 'public-outbound'];

    /**
     * CloudonixSettings has no OrganizationScope — filter explicitly.
     */
    private function client(int $organizationId): CloudonixClient
    {
        $settings = CloudonixSettings::where('organization_id', $organizationId)->firstOrFail();

        return new CloudonixClient($settings);
    }

    private function authorizeTrunks(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isOwner() || $user->isPBXAdmin()), 403);
    }

    /**
     * List trunks, optionally filtered by ?direction=.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeTrunks($request);
        $organizationId = (int) $request->user()->organization_id;

        $trunks = $this->client($organizationId)->trunks()->listTrunks();

        if ($trunks === null) {
            Log::warning('Cloudonix trunk list unavailable', ['organization_id' => $organizationId]);

            return $this->cloudonixUnavailable();
        }

        $direction = $request->query('direction');
        if (is_string($direction) && $direction !== '') {
            $trunks = array_values(array_filter(
                $trunks,
                fn (array $trunk): bool => ($trunk['direction'] ?? null) === $direction
            ));
        }

        $inUseBy = $this->inUseByMap($trunks);

        Log::info('Listed trunks', [
            'organization_id' => $organizationId,
            'count' => count($trunks),
        ]);

        return response()->json([
            'data' => array_map(
                fn (array $trunk): array => TrunkResource::present(
                    $trunk,
                    $inUseBy[$trunk['name'] ?? ''] ?? []
                ),
                $trunks
            ),
        ]);
    }

    /**
     * Show a single trunk by Cloudonix id/uuid.
     */
    public function show(Request $request, string $trunk): JsonResponse
    {
        $this->authorizeTrunks($request);
        $organizationId = (int) $request->user()->organization_id;

        $client = $this->client($organizationId);
        $payload = $client->trunks()->getTrunk($trunk);

        if ($payload === null) {
            return $this->trunkFetchError($client->trunks());
        }

        $inUseBy = $this->inUseByFor($payload['name'] ?? null);

        Log::info('Fetched trunk', [
            'organization_id' => $organizationId,
            'trunk_id' => $trunk,
        ]);

        return response()->json(['data' => TrunkResource::present($payload, $inUseBy)]);
    }

    /**
     * Create a trunk in Cloudonix.
     */
    public function store(StoreTrunkRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $validated = $request->validated();

        $payload = [
            'name' => $validated['name'],
            'direction' => $validated['direction'],
            'ip' => $validated['ip'],
            'port' => (int) $validated['port'],
            'transport' => $validated['transport'],
        ];

        if (! empty($validated['prefix'])) {
            $payload['prefix'] = $validated['prefix'];
        }

        $auth = $this->authenticationPayload($validated);
        if ($auth !== []) {
            $payload['profile'] = ['authentication' => $auth];
        }

        $created = $this->client($organizationId)->trunks()->createTrunk($payload);

        if ($created === null) {
            Log::warning('Cloudonix trunk creation failed', [
                'organization_id' => $organizationId,
                'name' => $validated['name'],
            ]);

            return $this->cloudonixUnavailable();
        }

        Log::info('Created trunk', [
            'organization_id' => $organizationId,
            'trunk_id' => $created['id'] ?? null,
            'name' => $validated['name'],
        ]);

        return response()->json(['data' => TrunkResource::present($created)], 201);
    }

    /**
     * Update a trunk in Cloudonix. Public-* trunks are read-only.
     */
    public function update(UpdateTrunkRequest $request, string $trunk): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $client = $this->client($organizationId);

        $existing = $client->trunks()->getTrunk($trunk);

        if ($existing === null) {
            return $this->trunkFetchError($client->trunks());
        }

        if ($readonly = $this->publicTrunkGuard($existing, $organizationId, 'update')) {
            return $readonly;
        }

        $validated = $request->validated();
        $payload = [];

        foreach (['ip', 'transport', 'prefix'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('port', $validated)) {
            $payload['port'] = (int) $validated['port'];
        }

        $auth = $this->authenticationPayload($validated);
        if ($auth !== []) {
            $payload['profile'] = ['authentication' => $auth];
        }

        $updated = $client->trunks()->updateTrunk($trunk, $payload);

        if ($updated === null) {
            Log::warning('Cloudonix trunk update failed', [
                'organization_id' => $organizationId,
                'trunk_id' => $trunk,
            ]);

            return $this->cloudonixUnavailable();
        }

        Log::info('Updated trunk', [
            'organization_id' => $organizationId,
            'trunk_id' => $trunk,
        ]);

        return response()->json(['data' => TrunkResource::present($updated)]);
    }

    /**
     * Delete a trunk in Cloudonix. Public-* trunks are read-only.
     */
    public function destroy(Request $request, string $trunk): JsonResponse
    {
        $this->authorizeTrunks($request);
        $organizationId = (int) $request->user()->organization_id;
        $client = $this->client($organizationId);

        $existing = $client->trunks()->getTrunk($trunk);

        if ($existing === null) {
            return $this->trunkFetchError($client->trunks());
        }

        if ($readonly = $this->publicTrunkGuard($existing, $organizationId, 'delete')) {
            return $readonly;
        }

        if (! $client->trunks()->deleteTrunk($trunk)) {
            Log::warning('Cloudonix trunk deletion failed', [
                'organization_id' => $organizationId,
                'trunk_id' => $trunk,
            ]);

            return $this->cloudonixUnavailable();
        }

        Log::info('Deleted trunk', [
            'organization_id' => $organizationId,
            'trunk_id' => $trunk,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Build the nested Cloudonix profile.authentication payload from flat
     * request fields. Password is included only when non-empty so an update
     * without a password never touches stored credentials.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function authenticationPayload(array $validated): array
    {
        $auth = [];

        if (! empty($validated['username'])) {
            $auth['username'] = $validated['username'];
        }

        if (! empty($validated['password'])) {
            $auth['password'] = $validated['password'];
        }

        if ($auth !== [] && array_key_exists('overwrite_from', $validated)) {
            $auth['overwrite-from'] = (bool) $validated['overwrite_from'];
        }

        return $auth;
    }

    /**
     * Names of outbound-whitelist entries per trunk name, in one query.
     *
     * @param  array<int, array<string, mixed>>  $trunks
     * @return array<string, array<int, string>>
     */
    private function inUseByMap(array $trunks): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (array $trunk) => $trunk['name'] ?? null, $trunks)
        )));

        if ($names === []) {
            return [];
        }

        return OutboundWhitelist::query()
            ->whereIn('outbound_trunk_name', $names)
            ->get(['name', 'outbound_trunk_name'])
            ->groupBy('outbound_trunk_name')
            ->map(fn ($entries) => $entries->pluck('name')->values()->all())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function inUseByFor(?string $trunkName): array
    {
        if (empty($trunkName)) {
            return [];
        }

        return OutboundWhitelist::query()
            ->where('outbound_trunk_name', $trunkName)
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $trunk
     */
    private function publicTrunkGuard(array $trunk, int $organizationId, string $action): ?JsonResponse
    {
        if (! in_array($trunk['direction'] ?? '', self::PUBLIC_DIRECTIONS, true)) {
            return null;
        }

        Log::warning('Blocked mutation of public trunk', [
            'organization_id' => $organizationId,
            'trunk_id' => $trunk['id'] ?? null,
            'direction' => $trunk['direction'] ?? null,
            'action' => $action,
        ]);

        return response()->json([
            'error' => 'public_trunk_read_only',
            'message' => 'Public trunks are managed by Cloudonix and cannot be modified.',
        ], 403);
    }

    /**
     * Map a null getTrunk() result to 404 (Cloudonix said "not found") or
     * 502 (upstream failure / unreachable) based on the last HTTP status.
     */
    private function trunkFetchError(CloudonixTrunksClient $trunks): JsonResponse
    {
        if ($trunks->getLastHttpStatus() === 404) {
            return response()->json(['error' => 'not_found', 'message' => 'Trunk not found.'], 404);
        }

        return $this->cloudonixUnavailable();
    }

    private function cloudonixUnavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'cloudonix_unavailable',
            'message' => 'Unable to reach the Cloudonix API. Please try again later.',
        ], 502);
    }
}
