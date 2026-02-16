<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\Extension;
use App\Models\OutboundWhitelist;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\PhoneNumberService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Outbound Routing Service
 *
 * Handles outbound call routing for calls from internal extensions to external
 * destinations. Validates outbound whitelist entries and routes via configured trunks.
 */
class OutboundRoutingService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly VoiceRoutingCacheService $cache
    ) {}

    /**
     * Handle outbound routing via whitelist for calls from internal extensions.
     *
     * @param Request $request The incoming call request
     * @param string $to The destination phone number
     * @param string $from The caller phone number
     * @param int $orgId The organization ID
     * @return Response|null CXML response if outbound routing applies, null otherwise
     */
    public function handleOutboundRouting(Request $request, string $to, string $from, int $orgId): ?Response
    {
        Log::info('OutboundRoutingService: Checking for outbound whitelist routing', [
            'direction' => $request->input('Direction', 'unknown'),
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Only allow outbound routing for calls from internal extensions
        $fromExtension = $this->cache->getExtension($orgId, $from);

        if (! $fromExtension || ! $fromExtension->isActive()) {
            Log::info('OutboundRoutingService: Outbound routing not allowed - caller is not an active internal extension', [
                'from' => $from,
                'extension_found' => $fromExtension !== null,
                'extension_active' => $fromExtension?->isActive(),
            ]);

            return null;
        }

        Log::info('OutboundRoutingService: Caller is active internal extension, checking outbound whitelist', [
            'from' => $from,
            'extension_found' => $fromExtension !== null,
            'extension_active' => $fromExtension->isActive(),
        ]);

        // Use sophisticated whitelist matching (country code, prefix matching)
        $whitelistEntry = $this->findOutboundWhitelistEntry($orgId, $to);

        if (! $whitelistEntry) {
            Log::info('OutboundRoutingService: No outbound whitelist entry found for destination', [
                'to' => $to,
                'org_id' => $orgId,
            ]);

            return null;
        }

        $trunkName = $whitelistEntry->outbound_trunk_name;

        if (empty($trunkName)) {
            Log::error('OutboundRoutingService: Whitelist entry has no trunk configured', [
                'to' => $to,
                'org_id' => $orgId,
                'whitelist_entry_id' => $whitelistEntry->id,
            ]);

            return response(
                CxmlBuilder::unavailable('Outbound route configuration error'),
                200,
                ['Content-Type' => 'text/xml']
            );
        }

        Log::info('OutboundRoutingService: Routing outbound call via trunk', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
            'trunk_name' => $trunkName,
            'whitelist_entry_id' => $whitelistEntry->id,
        ]);

        // Route via trunk using the caller ID from the extension
        $callerId = $fromExtension->caller_id ?? $from;

        return response(
            CxmlBuilder::simpleDial($to, $callerId, null, $trunkName),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    /**
     * Find the best matching outbound whitelist entry for a destination.
     *
     * Matching algorithm:
     * 1. Get all whitelist entries for the organization
     * 2. Extract country calling code from destination
     * 3. Match against destination_country (ISO code or calling code)
     * 4. Within matches, check destination_prefix for additional matching
     * 5. Return the longest matching prefix
     *
     * @param int $organizationId The organization ID
     * @param string $destinationNumber The destination phone number
     * @return OutboundWhitelist|null The best matching whitelist entry or null
     */
    public function findOutboundWhitelistEntry(int $organizationId, string $destinationNumber): ?OutboundWhitelist
    {
        $whitelistEntries = OutboundWhitelist::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->get();

        Log::debug('OutboundRoutingService: Checking outbound whitelist entries', [
            'organization_id' => $organizationId,
            'destination_number' => $destinationNumber,
            'whitelist_entries_count' => $whitelistEntries->count(),
        ]);

        // Extract country calling code from destination number
        $callingCode = $this->phoneNumberService->extractCallingCode($destinationNumber);
        $countryCode = $callingCode ? $this->phoneNumberService->callingCodeToCountryCode($callingCode) : null;

        Log::debug('OutboundRoutingService: Extracted calling code and country code', [
            'destination_number' => $destinationNumber,
            'calling_code' => $callingCode,
            'country_code' => $countryCode,
        ]);

        $matches = [];

        foreach ($whitelistEntries as $entry) {
            $matchScore = 0;
            $matchReason = '';

            // Check country code match (both ISO codes and calling codes)
            if ($countryCode && $entry->destination_country === $countryCode) {
                $matchScore += 10;
                $matchReason .= 'country_match ';
            } elseif ($callingCode && $entry->destination_country === $callingCode) {
                $matchScore += 10;
                $matchReason .= 'calling_code_match ';
            } elseif ($callingCode && $entry->destination_country === ltrim($callingCode, '+')) {
                $matchScore += 10;
                $matchReason .= 'calling_code_no_plus_match ';
            }

            // Check prefix match
            if (! empty($entry->destination_prefix)) {
                $normalizedPrefix = str_replace(' ', '', $entry->destination_prefix);

                if (str_starts_with($normalizedPrefix, '+')) {
                    if (str_starts_with($destinationNumber, $normalizedPrefix)) {
                        $prefixLength = strlen($normalizedPrefix);
                        $matchScore += $prefixLength;
                        $matchReason .= "full_prefix_match({$prefixLength}) ";
                    }
                }
            }

            if ($matchScore > 0) {
                $matches[] = [
                    'entry' => $entry,
                    'score' => $matchScore,
                    'reason' => $matchReason,
                ];
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Sort by score (descending) and return the best match
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
        $bestMatch = $matches[0];

        Log::info('OutboundRoutingService: Best whitelist match found', [
            'entry_id' => $bestMatch['entry']->id,
            'score' => $bestMatch['score'],
            'reason' => $bestMatch['reason'],
        ]);

        return $bestMatch['entry'];
    }
}
