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
            fallbackValue: []
        );
    }
}
