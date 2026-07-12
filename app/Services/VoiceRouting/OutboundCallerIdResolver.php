<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the outbound caller ID to present for a call originating from an
 * internal extension.
 *
 * Resolution order (see design spec 2026-07-12-outbound-caller-id-design):
 *   1. The extension's own assigned inbound DID (reverse-derived from routing_config).
 *   2. The organization's designated default outbound caller ID DID.
 *   3. "Unknown" — no assigned number; present the literal "Unknown" for both
 *      callerId and callerName (Cloudonix expects callerId set to "Unknown").
 *
 * Runs outside an authenticated tenant context (voice routing webhooks), so all
 * queries bypass OrganizationScope and filter by organization_id explicitly,
 * mirroring the rest of the voice routing engine.
 */
final class OutboundCallerIdResolver
{
    /**
     * Resolve the caller ID and caller name to present for an outbound call.
     *
     * @return array{callerId: ?string, callerName: ?string}
     */
    public function resolve(Extension $fromExtension, int $orgId): array
    {
        // Step 1 — the extension's own assigned inbound DID (reverse-derive).
        $extensionDid = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('routing_type', 'extension')
            ->where('routing_config->extension_id', $fromExtension->id)
            ->orderBy('status') // 'active' sorts before 'inactive', preferring active
            ->orderBy('id')
            ->first();

        if ($extensionDid !== null) {
            Log::info('OutboundCallerIdResolver: resolved from extension DID', [
                'org_id' => $orgId,
                'extension_id' => $fromExtension->id,
                'did_id' => $extensionDid->id,
                'caller_id' => $extensionDid->phone_number,
            ]);

            return ['callerId' => $extensionDid->phone_number, 'callerName' => null];
        }

        // Step 2 — the organization's default outbound caller ID DID.
        $organization = Organization::find($orgId);
        $defaultDidId = $organization?->settings['default_outbound_caller_id_did_id'] ?? null;

        if ($defaultDidId !== null) {
            $defaultDid = DidNumber::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('id', (int) $defaultDidId)
                ->where('status', 'active')
                ->first();

            if ($defaultDid !== null) {
                Log::info('OutboundCallerIdResolver: resolved from org default DID', [
                    'org_id' => $orgId,
                    'extension_id' => $fromExtension->id,
                    'did_id' => $defaultDid->id,
                    'caller_id' => $defaultDid->phone_number,
                ]);

                return ['callerId' => $defaultDid->phone_number, 'callerName' => null];
            }

            Log::debug('OutboundCallerIdResolver: configured default DID invalid or inactive', [
                'org_id' => $orgId,
                'extension_id' => $fromExtension->id,
                'configured_did_id' => $defaultDidId,
            ]);
        }

        // Step 3 — no assigned number; present the literal "Unknown".
        // Cloudonix requires callerId to be set explicitly to "Unknown" for
        // withheld/anonymous presentation, not omitted.
        Log::info('OutboundCallerIdResolver: no caller ID resolved, presenting Unknown', [
            'org_id' => $orgId,
            'extension_id' => $fromExtension->id,
        ]);

        return ['callerId' => 'Unknown', 'callerName' => 'Unknown'];
    }
}
