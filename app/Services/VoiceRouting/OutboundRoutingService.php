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
        private readonly VoiceRoutingCacheService $cache,
        private readonly OutboundCallerIdResolver $callerIdResolver
    ) {}

    /**
     * Handle outbound routing via whitelist for calls from internal extensions.
     *
     * @param  Request  $request  The incoming call request
     * @param  string  $to  The destination phone number
     * @param  string  $from  The caller phone number
     * @param  int  $orgId  The organization ID
     * @return Response|null CXML response if outbound routing applies, null otherwise
     */
    public function handleOutboundRouting(Request $request, string $to, string $from, int $orgId): ?Response
    {
        Log::info('OutboundRoutingService: ========== OUTBOUND ROUTING START ==========');
        Log::info('OutboundRoutingService: Checking for outbound whitelist routing', [
            'direction' => $request->input('Direction', 'unknown'),
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
        ]);

        // Check if destination looks like an external phone number (E.164 format or numeric)
        // Skip outbound routing for internal extension calls (3-4 digit numbers)
        $toDigits = preg_replace('/[^0-9]/', '', $to);
        if (strlen($toDigits) < 7) {
            Log::debug('OutboundRoutingService: Destination appears to be internal extension, skipping outbound routing', [
                'to' => $to,
                'to_digits' => $toDigits,
                'digit_count' => strlen($toDigits),
            ]);

            return null;
        }

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
            Log::warning('OutboundRoutingService: Call rejected - destination not in outbound whitelist', [
                'to' => $to,
                'from' => $from,
                'org_id' => $orgId,
            ]);

            return response(
                CxmlBuilder::unavailable('Outbound calls to this destination are not allowed'),
                200,
                ['Content-Type' => 'application/xml']
            );
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
                ['Content-Type' => 'application/xml']
            );
        }

        Log::info('OutboundRoutingService: Routing outbound call via trunk', [
            'to' => $to,
            'from' => $from,
            'org_id' => $orgId,
            'trunk_name' => $trunkName,
            'whitelist_entry_id' => $whitelistEntry->id,
        ]);

        // Resolve the outbound caller ID to present.
        // Precedence: extension's selected DID -> whitelist rule's selected DID -> "00000000".
        // ponytail: callerName always null now, kept for the simpleDial() signature.
        $resolved = $this->callerIdResolver->resolve($fromExtension, $whitelistEntry, $orgId);

        Log::info('OutboundRoutingService: Resolved outbound caller ID', [
            'from' => $from,
            'org_id' => $orgId,
            'caller_id' => $resolved['callerId'],
            'caller_name' => $resolved['callerName'],
        ]);

        return response(
            CxmlBuilder::simpleDial($to, $resolved['callerId'], null, $trunkName, $resolved['callerName']),
            200,
            ['Content-Type' => 'application/xml']
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
     * @param  int  $organizationId  The organization ID
     * @param  string  $destinationNumber  The destination phone number
     * @return OutboundWhitelist|null The best matching whitelist entry or null
     */
    public function findOutboundWhitelistEntry(int $organizationId, string $destinationNumber): ?OutboundWhitelist
    {
        $whitelistEntries = OutboundWhitelist::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get();

        Log::debug('OutboundRoutingService: Checking active outbound whitelist entries', [
            'organization_id' => $organizationId,
            'destination_number' => $destinationNumber,
            'whitelist_entries_count' => $whitelistEntries->count(),
        ]);

        // Log all entries for debugging
        foreach ($whitelistEntries as $entry) {
            Log::debug('OutboundRoutingService: Whitelist entry', [
                'entry_id' => $entry->id,
                'name' => $entry->name,
                'destination_country' => $entry->destination_country,
                'destination_prefix' => $entry->destination_prefix,
                'outbound_trunk_name' => $entry->outbound_trunk_name,
            ]);
        }

        // Normalize phone number first (ensure + prefix for proper country code extraction)
        $normalizedNumber = $this->phoneNumberService->normalizeToE164($destinationNumber);

        // Extract country calling code from destination number
        $callingCode = $normalizedNumber ? $this->phoneNumberService->extractCallingCode($normalizedNumber) : null;
        $countryCode = $callingCode ? $this->phoneNumberService->callingCodeToCountryCode($callingCode) : null;

        Log::debug('OutboundRoutingService: Extracted calling code and country code', [
            'destination_number' => $destinationNumber,
            'normalized_number' => $normalizedNumber,
            'calling_code' => $callingCode,
            'country_code' => $countryCode,
        ]);

        $matches = [];

        foreach ($whitelistEntries as $entry) {
            $matchScore = 0;
            $matchReason = '';
            $countryMatched = false;

            Log::debug('OutboundRoutingService: Evaluating entry', [
                'entry_id' => $entry->id,
                'name' => $entry->name,
                'destination_country' => $entry->destination_country,
                'destination_prefix' => $entry->destination_prefix,
            ]);

            // Check country code match (both ISO codes and calling codes)
            if ($countryCode && $entry->destination_country === $countryCode) {
                $countryMatched = true;
                $matchScore += 10;
                $matchReason .= 'country_match ';
            } elseif ($callingCode && $entry->destination_country === $callingCode) {
                $countryMatched = true;
                $matchScore += 10;
                $matchReason .= 'calling_code_match ';
            } elseif ($callingCode && $entry->destination_country === ltrim($callingCode, '+')) {
                $countryMatched = true;
                $matchScore += 10;
                $matchReason .= 'calling_code_no_plus_match ';
            }

            // If country doesn't match, skip this entry entirely
            if (! $countryMatched) {
                Log::debug('OutboundRoutingService: Entry country did not match', [
                    'entry_id' => $entry->id,
                    'entry_country' => $entry->destination_country,
                    'destination_country_code' => $countryCode,
                ]);

                continue;
            }

            // Check prefix match - REQUIRED if prefix is specified
            $prefixMatched = false;
            if (! empty($entry->destination_prefix)) {
                $normalizedPrefix = str_replace(' ', '', $entry->destination_prefix);
                $destWithoutPlus = ltrim($destinationNumber, '+');

                Log::debug('OutboundRoutingService: Checking prefix', [
                    'entry_id' => $entry->id,
                    'prefix' => $normalizedPrefix,
                    'destination' => $destinationNumber,
                    'dest_without_plus' => $destWithoutPlus,
                ]);

                if (str_starts_with($normalizedPrefix, '+')) {
                    // Full international prefix (e.g., +12123)
                    $checkResult = str_starts_with($destinationNumber, $normalizedPrefix);
                    Log::debug('OutboundRoutingService: Checking +prefixed prefix', [
                        'destinationNumber' => $destinationNumber,
                        'normalizedPrefix' => $normalizedPrefix,
                        'checkResult' => $checkResult,
                    ]);
                    if ($checkResult) {
                        $prefixLength = strlen($normalizedPrefix);
                        $matchScore += $prefixLength;
                        $matchReason .= "full_prefix_match({$prefixLength}) ";
                        $prefixMatched = true;
                    }
                } else {
                    // Prefix without + (e.g., 2123) - match after the country code
                    // Strip the country code from the beginning of the number
                    $numberAfterCountryCode = $destWithoutPlus;
                    if ($callingCode) {
                        $codeWithoutPlus = ltrim($callingCode, '+');
                        if (str_starts_with($destWithoutPlus, $codeWithoutPlus)) {
                            $numberAfterCountryCode = substr($destWithoutPlus, strlen($codeWithoutPlus));
                        }
                    }

                    $checkResult = str_starts_with($numberAfterCountryCode, $normalizedPrefix);
                    Log::debug('OutboundRoutingService: Checking prefix after country code', [
                        'destWithoutPlus' => $destWithoutPlus,
                        'numberAfterCountryCode' => $numberAfterCountryCode,
                        'normalizedPrefix' => $normalizedPrefix,
                        'checkResult' => $checkResult,
                    ]);
                    if ($checkResult) {
                        $prefixLength = strlen($normalizedPrefix);
                        $matchScore += $prefixLength;
                        $matchReason .= "prefix_match({$prefixLength}) ";
                        $prefixMatched = true;
                    }
                }

                // If entry has a prefix but it didn't match, reject this entry
                if (! $prefixMatched) {
                    Log::debug('OutboundRoutingService: Entry prefix did not match - rejecting', [
                        'entry_id' => $entry->id,
                        'prefix' => $normalizedPrefix,
                        'destination' => $destinationNumber,
                    ]);

                    continue;
                }
            }

            if ($matchScore > 0) {
                $matches[] = [
                    'entry' => $entry,
                    'score' => $matchScore,
                    'reason' => $matchReason,
                ];
                Log::info('OutboundRoutingService: Entry matched', [
                    'entry_id' => $entry->id,
                    'score' => $matchScore,
                    'reason' => $matchReason,
                ]);
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
