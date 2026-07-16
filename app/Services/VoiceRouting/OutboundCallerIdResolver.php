<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\OutboundWhitelist;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the outbound caller ID to present for a call originating from an
 * internal extension and routed via an outbound whitelist rule.
 *
 * Applies ONLY to outbound calls (internal extension -> external destination).
 * Internal and inbound calls do not use this resolver.
 *
 * Resolution order:
 *   1. The from-extension's selected default caller ID DID (active).
 *   2. The matched whitelist rule's selected default caller ID DID (active).
 *   3. The literal "00000000" — no caller ID configured anywhere.
 *
 * Runs outside an authenticated tenant context (voice routing webhooks), so all
 * queries bypass OrganizationScope and filter by organization_id explicitly,
 * mirroring the rest of the voice routing engine.
 */
final class OutboundCallerIdResolver
{
    /**
     * Fallback caller ID presented when nothing is configured.
     */
    public const NO_CALLER_ID = '00000000';

    /**
     * Resolve the caller ID and caller name to present for an outbound call.
     *
     * @return array{callerId: string, callerName: ?string}
     */
    public function resolve(Extension $fromExtension, OutboundWhitelist $whitelistEntry, int $orgId): array
    {
        // Step 1 — the extension's explicitly selected caller ID DID.
        $extensionCallerId = $this->activeDidPhoneNumber($fromExtension->default_caller_id_did_id, $orgId);

        if ($extensionCallerId !== null) {
            Log::info('OutboundCallerIdResolver: resolved from extension caller ID', [
                'org_id' => $orgId,
                'extension_id' => $fromExtension->id,
                'caller_id' => $extensionCallerId,
            ]);

            return ['callerId' => $extensionCallerId, 'callerName' => null];
        }

        // Step 2 — the matched whitelist rule's selected caller ID DID.
        $whitelistCallerId = $this->activeDidPhoneNumber($whitelistEntry->default_caller_id_did_id, $orgId);

        if ($whitelistCallerId !== null) {
            Log::info('OutboundCallerIdResolver: resolved from whitelist caller ID', [
                'org_id' => $orgId,
                'whitelist_entry_id' => $whitelistEntry->id,
                'caller_id' => $whitelistCallerId,
            ]);

            return ['callerId' => $whitelistCallerId, 'callerName' => null];
        }

        // Step 3 — nothing configured; present the zero caller ID.
        Log::info('OutboundCallerIdResolver: no caller ID configured, presenting zeros', [
            'org_id' => $orgId,
            'extension_id' => $fromExtension->id,
            'whitelist_entry_id' => $whitelistEntry->id,
        ]);

        return ['callerId' => self::NO_CALLER_ID, 'callerName' => null];
    }

    /**
     * Return the phone number of an active DID owned by the given org, or null.
     */
    private function activeDidPhoneNumber(?int $didId, int $orgId): ?string
    {
        if ($didId === null) {
            return null;
        }

        $did = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('id', $didId)
            ->where('status', 'active')
            ->first();

        return $did?->phone_number;
    }
}
