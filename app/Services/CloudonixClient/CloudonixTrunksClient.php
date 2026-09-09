<?php

declare(strict_types=1);

namespace App\Services\CloudonixClient;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cloudonix API client for trunk management operations.
 *
 * Handles trunk listing, filtering, and configuration retrieval.
 *
 * @see https://developers.cloudonix.com/cloudonixRestOpenAPI
 */
class CloudonixTrunksClient extends CloudonixBaseClient
{
    /**
     * List outbound trunks for the domain.
     *
     * Fetches trunks from /customers/{customer-id}/domains/{domain-id}/trunks
     * and filters for trunks with direction "public-outbound" and "outbound" only.
     *
     * @return array<array<string, mixed>>|null Array of outbound trunk objects or null on failure
     */
    public function listOutboundTrunks(): ?array
    {
        $this->requireDomainUuid();

        $cacheKey = "cloudonix:outbound_trunks:{$this->getDomainUuid()}";

        return $this->withCircuitBreaker(
            callback: function () use ($cacheKey) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks";

                    Log::debug('Cloudonix API request: List Outbound Trunks', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                    ]);

                    $response = $this->client()
                        ->get($url);

                    if ($response->successful()) {
                        $trunks = $response->json();

                        // Filter for outbound trunks only
                        $outboundTrunks = array_filter($trunks, function ($trunk) {
                            return isset($trunk['direction']) &&
                                   in_array($trunk['direction'], ['public-outbound', 'outbound'], true);
                        });

                        // Re-index array after filtering
                        $outboundTrunks = array_values($outboundTrunks);

                        Log::info('Successfully fetched outbound trunks from Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'total_trunks' => is_array($trunks) ? count($trunks) : 0,
                            'outbound_trunks' => count($outboundTrunks),
                            'status' => $response->status(),
                        ]);

                        // Cache filtered results for 5 minutes (trunk configurations change infrequently)
                        Cache::put($cacheKey, $outboundTrunks, now()->addMinutes(5));

                        return $outboundTrunks;
                    }

                    Log::warning('Failed to fetch outbound trunks from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while fetching outbound trunks from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: $cacheKey,
            fallbackValue: []
        );
    }

    /**
     * List all trunks for the domain.
     *
     * @return array<array<string, mixed>>|null Array of trunk objects or null on failure
     */
    public function listTrunks(): ?array
    {
        $this->requireDomainUuid();

        $cacheKey = "cloudonix:trunks:{$this->getDomainUuid()}";

        return $this->withCircuitBreaker(
            callback: function () use ($cacheKey) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks";

                    Log::debug('Cloudonix API request: List Trunks', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                    ]);

                    $response = $this->client()
                        ->get($url);

                    if ($response->successful()) {
                        $trunks = $response->json();

                        Log::info('Successfully fetched trunks from Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'trunks_count' => is_array($trunks) ? count($trunks) : 0,
                            'status' => $response->status(),
                        ]);

                        // Cache results for 5 minutes
                        Cache::put($cacheKey, $trunks, now()->addMinutes(5));

                        return $trunks;
                    }

                    Log::warning('Failed to fetch trunks from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while fetching trunks from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: $cacheKey,
            fallbackValue: null
        );
    }

    /**
     * Create a new trunk for the domain.
     *
     * @param  array<string, mixed>  $data  Trunk configuration payload
     * @return array<string, mixed>|null Created trunk object or null on failure
     */
    public function createTrunk(array $data): ?array
    {
        $this->requireDomainUuid();

        return $this->withCircuitBreaker(
            callback: function () use ($data) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks";

                    Log::debug('Cloudonix API request: Create Trunk', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                    ]);

                    $response = $this->client()
                        ->post($url, $data);

                    if ($response->successful()) {
                        $trunk = $response->json();

                        Log::info('Successfully created trunk in Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'status' => $response->status(),
                        ]);

                        $this->forgetTrunkCaches();

                        return $trunk;
                    }

                    Log::warning('Failed to create trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while creating trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: null
        );
    }

    /**
     * Get a single trunk by ID.
     *
     * @return array<string, mixed>|null Trunk object or null on failure
     */
    public function getTrunk(int|string $trunkId): ?array
    {
        $this->requireDomainUuid();

        return $this->withCircuitBreaker(
            callback: function () use ($trunkId) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks/{$trunkId}";

                    Log::debug('Cloudonix API request: Get Trunk', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                    ]);

                    $response = $this->client()
                        ->get($url);

                    if ($response->successful()) {
                        $trunk = $response->json();

                        Log::info('Successfully fetched trunk from Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'trunk_id' => $trunkId,
                            'status' => $response->status(),
                        ]);

                        return $trunk;
                    }

                    Log::warning('Failed to fetch trunk from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while fetching trunk from Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: null
        );
    }

    /**
     * Update an existing trunk.
     *
     * @param  array<string, mixed>  $data  Trunk fields to update
     * @return array<string, mixed>|null Updated trunk object or null on failure
     */
    public function updateTrunk(int|string $trunkId, array $data): ?array
    {
        $this->requireDomainUuid();

        return $this->withCircuitBreaker(
            callback: function () use ($trunkId, $data) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks/{$trunkId}";

                    Log::debug('Cloudonix API request: Update Trunk', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                    ]);

                    $response = $this->client()
                        ->put($url, $data);

                    if ($response->successful()) {
                        $trunk = $response->json();

                        Log::info('Successfully updated trunk in Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'trunk_id' => $trunkId,
                            'status' => $response->status(),
                        ]);

                        $this->forgetTrunkCaches();

                        return $trunk;
                    }

                    Log::warning('Failed to update trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                } catch (\Exception $e) {
                    Log::error('Exception while updating trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: null
        );
    }

    /**
     * Delete a trunk.
     *
     * @return bool True on successful deletion, false on failure
     */
    public function deleteTrunk(int|string $trunkId): bool
    {
        $this->requireDomainUuid();

        return (bool) $this->withCircuitBreaker(
            callback: function () use ($trunkId) {
                try {
                    $url = "/customers/{$this->getCustomerId()}/domains/{$this->getDomainUuid()}/trunks/{$trunkId}";

                    Log::debug('Cloudonix API request: Delete Trunk', [
                        'url' => $this->getBaseUrl().$url,
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                    ]);

                    $response = $this->client()
                        ->delete($url);

                    if ($response->successful()) {
                        Log::info('Successfully deleted trunk in Cloudonix', [
                            'domain_uuid' => $this->getDomainUuid(),
                            'trunk_id' => $trunkId,
                            'status' => $response->status(),
                        ]);

                        $this->forgetTrunkCaches();

                        return true;
                    }

                    Log::warning('Failed to delete trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'url' => $this->getBaseUrl().$url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return false;
                } catch (\Exception $e) {
                    Log::error('Exception while deleting trunk in Cloudonix', [
                        'domain_uuid' => $this->getDomainUuid(),
                        'trunk_id' => $trunkId,
                        'exception' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            },
            cacheKey: null,
            fallbackValue: false
        );
    }

    /**
     * Invalidate cached trunk lists after a mutation.
     */
    private function forgetTrunkCaches(): void
    {
        Cache::forget("cloudonix:trunks:{$this->getDomainUuid()}");
        Cache::forget("cloudonix:outbound_trunks:{$this->getDomainUuid()}");
    }
}
