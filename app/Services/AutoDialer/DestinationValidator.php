<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Services\VoiceRouting\OutboundRoutingService;
use Illuminate\Support\Facades\Log;

/**
 * Destination Validator Service
 *
 * Validates phone numbers against outbound whitelist rules.
 */
class DestinationValidator
{
    public function __construct(
        private readonly OutboundRoutingService $outboundRoutingService,
    ) {}

    /**
     * Validate a destination phone number.
     *
     * @param  string  $phoneNumber  The phone number to validate (E.164 format)
     * @param  int  $organizationId  The organization ID
     * @return array{valid: bool, error: string|null, trunk: string|null}
     */
    public function validate(string $phoneNumber, int $organizationId): array
    {
        Log::debug('Validating destination', [
            'phone_number' => $phoneNumber,
            'organization_id' => $organizationId,
        ]);

        // Check if phone number is valid E.164 format
        if (! $this->isValidE164($phoneNumber)) {
            return [
                'valid' => false,
                'error' => 'Invalid phone number format. Must be E.164 format (e.g., +14155551212).',
                'trunk' => null,
            ];
        }

        // Find matching whitelist entry
        $whitelistEntry = $this->outboundRoutingService->findOutboundWhitelistEntry(
            $organizationId,
            $phoneNumber
        );

        if (! $whitelistEntry) {
            Log::info('Destination rejected - no whitelist match', [
                'phone_number' => $phoneNumber,
                'organization_id' => $organizationId,
            ]);

            return [
                'valid' => false,
                'error' => 'Destination does not match any outbound whitelist rule.',
                'trunk' => null,
            ];
        }

        // Get trunk name
        $trunkName = $whitelistEntry->outbound_trunk_name;

        if (empty($trunkName)) {
            Log::error('Whitelist entry has no trunk configured', [
                'whitelist_id' => $whitelistEntry->id,
                'phone_number' => $phoneNumber,
            ]);

            return [
                'valid' => false,
                'error' => 'No outbound trunk configured for this destination.',
                'trunk' => null,
            ];
        }

        Log::info('Destination validated successfully', [
            'phone_number' => $phoneNumber,
            'trunk' => $trunkName,
            'whitelist_id' => $whitelistEntry->id,
        ]);

        return [
            'valid' => true,
            'error' => null,
            'trunk' => $trunkName,
        ];
    }

    /**
     * Check if a phone number is valid E.164 format.
     */
    private function isValidE164(string $phoneNumber): bool
    {
        // E.164 format: + followed by 1-15 digits
        return preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber) === 1;
    }
}
