<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\Extension;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles supervisor call-coaching sentinels (spy/whisper/barge) dialed from a
 * supervisor's Web Phone, emitting a Cloudonix <Coach> verb.
 *
 * Destination grammar:
 *   spy_{token}              -> policy="listen"
 *   barge_{token}            -> policy="barge"
 *   whisper_{party}_{token}  -> policy="whisper" + whisperDirection={party}
 * where party in {caller, callee, both} and token is hex [0-9a-f]{16,}.
 *
 * Runs at webhook time outside an authenticated tenant context: extension
 * lookups bypass OrganizationScope and filter by organization_id explicitly.
 * This is the real security boundary — anyone who knows the sentinel format
 * could dial it, so the calling extension's user role is re-verified here.
 */
final class CoachRoutingService
{
    private const SPY_BARGE_PATTERN = '/^(spy|barge)_([0-9a-f]{16,})$/';

    private const WHISPER_PATTERN = '/^whisper_(caller|callee|both)_([0-9a-f]{16,})$/';

    private const POLICY_MAP = [
        'spy' => 'listen',
        'barge' => 'barge',
    ];

    /**
     * Attempt to handle a coaching sentinel. Returns null when the destination
     * is not a coach sentinel so normal routing can proceed.
     */
    public function tryHandle(Request $request): ?Response
    {
        $to = (string) $request->input('To', '');

        $policy = null;
        $whisperDirection = null;
        $token = null;

        if (preg_match(self::SPY_BARGE_PATTERN, $to, $m) === 1) {
            $policy = self::POLICY_MAP[$m[1]];
            $token = $m[2];
        } elseif (preg_match(self::WHISPER_PATTERN, $to, $m) === 1) {
            $policy = 'whisper';
            $whisperDirection = $m[1];
            $token = $m[2];
        } else {
            return null;
        }

        $from = (string) $request->input('From', '');
        $orgId = (int) $request->input('_organization_id');

        // Runs unauthenticated at webhook time: bypass OrganizationScope for the
        // extension AND its eager-loaded user (both are tenant-scoped models), then
        // constrain organization_id explicitly. Without the bypass the scope injects
        // `WHERE 1 = 0` on the user relation and the role check would always fail.
        $extension = OrganizationScope::bypass(fn () => Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('type', ExtensionType::USER)
            ->where('extension_number', $from)
            ->with('user')
            ->first());

        $role = $extension?->user?->role;
        $authorized = $role instanceof UserRole
            && in_array($role, [UserRole::OWNER, UserRole::SUPERVISOR], true);

        if (! $authorized) {
            Log::warning('CoachRoutingService: unauthorized coaching attempt', [
                'org_id' => $orgId,
                'from' => $from,
                'policy' => $policy,
                'token_prefix' => substr($token, 0, 6),
                'security_event' => true,
            ]);

            return $this->deny();
        }

        Log::info('CoachRoutingService: authorized coaching', [
            'org_id' => $orgId,
            'from' => $from,
            'extension_id' => $extension->id,
            'policy' => $policy,
            'whisper_direction' => $whisperDirection,
            'token_prefix' => substr($token, 0, 6),
            'security_event' => true,
        ]);

        return response(
            CxmlBuilder::coach($token, $policy, $whisperDirection),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    private function deny(): Response
    {
        return response(
            CxmlBuilder::sayWithHangup('You are not permitted to monitor this call.', true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
