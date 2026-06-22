<?php

declare(strict_types=1);

namespace App\Services\InboundBlacklist;

use App\Enums\InboundBlacklistRejectionStrategy;
use App\Enums\InboundBlacklistStatus;
use App\Models\BlockedCallLog;
use App\Models\InboundBlacklist;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class InboundBlacklistService
{
    /**
     * Check if a caller is blacklisted for the given DID.
     */
    public function isBlacklisted(string $callerId, int $didNumberId, int $organizationId): ?InboundBlacklist
    {
        // Get all active blacklist entries for this organization
        // Either global OR specific to this DID (via pivot table)
        $entries = InboundBlacklist::where('organization_id', $organizationId)
            ->where('status', InboundBlacklistStatus::ACTIVE)
            ->where(function ($query) use ($didNumberId) {
                $query->where('is_global', true)
                    ->orWhereHas('didNumbers', function ($q) use ($didNumberId) {
                        $q->where('did_numbers.id', $didNumberId);
                    });
            })
            ->get();

        foreach ($entries as $entry) {
            if ($entry->matches($callerId)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Generate CXML response for blacklisted caller.
     */
    public function generateRejectionCxml(InboundBlacklist $blacklist, Request $request): Response
    {
        $callerId = $request->input('From');
        $calledNumber = $request->input('To');

        return match ($blacklist->rejection_strategy) {
            InboundBlacklistRejectionStrategy::DROP => $this->generateDropResponse($blacklist, $request),
            InboundBlacklistRejectionStrategy::REJECT => $this->generateRejectResponse($blacklist, $request),
            InboundBlacklistRejectionStrategy::TORMENT => $this->generateTormentResponse($blacklist, $request),
        };
    }

    /**
     * Strategy: Drop - Silent hangup.
     */
    private function generateDropResponse(InboundBlacklist $blacklist, Request $request): Response
    {
        Log::info('InboundBlacklist: Dropping blacklisted call', [
            'caller_id' => $request->input('From'),
            'blacklist_id' => $blacklist->id,
        ]);

        $this->logBlockedCall($blacklist, $request, null, null);

        return response(
            CxmlBuilder::simpleHangup(),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Strategy: Reject - Message then hangup.
     */
    private function generateRejectResponse(InboundBlacklist $blacklist, Request $request): Response
    {
        Log::info('InboundBlacklist: Rejecting blacklisted call with message', [
            'caller_id' => $request->input('From'),
            'blacklist_id' => $blacklist->id,
        ]);

        $this->logBlockedCall($blacklist, $request, null, null);

        return response(
            CxmlBuilder::sayWithHangup('Your call has been rejected', true),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Strategy: Torment - Random conference room with music.
     */
    private function generateTormentResponse(InboundBlacklist $blacklist, Request $request): Response
    {
        $callerId = $request->input('From');

        // Generate random room ID
        $roomId = $this->generateTormentRoomId($blacklist);

        Log::info('InboundBlacklist: Tormenting blacklisted caller', [
            'caller_id' => $callerId,
            'blacklist_id' => $blacklist->id,
            'room_id' => $roomId,
        ]);

        $this->logBlockedCall($blacklist, $request, $roomId, null);

        // Use Dial + Conference for torment mode with a long timeout
        // This puts the caller in a conference room with hold music
        return response(
            CxmlBuilder::dialConference(
                $roomId,
                true, // startOnEnter
                false, // endOnExit
                100, // maxParticipants (high to allow multiple spammers in same trap)
                null, // waitUrl - use default hold music
                false, // muteOnEntry
                false, // announceJoinLeave
                $blacklist->torment_music_timeout
            ),
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Generate a random conference room ID for torment mode.
     */
    private function generateTormentRoomId(InboundBlacklist $blacklist): string
    {
        $prefix = $blacklist->torment_room_prefix ?? 'blacklist';
        $hash = substr(md5(uniqid((string) rand(), true)), 0, 12);

        return "{$prefix}-{$hash}";
    }

    /**
     * Log a blocked call for audit purposes.
     */
    private function logBlockedCall(
        InboundBlacklist $blacklist,
        Request $request,
        ?string $tormentRoomId,
        ?int $tormentDuration
    ): void {
        try {
            // Get the called DID number from the request
            $calledNumber = $request->input('To');

            // Try to find the DID that was called
            $didNumberId = null;
            if (! $blacklist->is_global) {
                // For non-global entries, find the specific DID that was called
                $didNumber = $blacklist->didNumbers()
                    ->where('phone_number', $calledNumber)
                    ->first();
                if ($didNumber) {
                    $didNumberId = $didNumber->id;
                }
            }

            BlockedCallLog::create([
                'organization_id' => $blacklist->organization_id,
                'inbound_blacklist_id' => $blacklist->id,
                'did_number_id' => $didNumberId,
                'caller_id' => $request->input('From'),
                'called_number' => $calledNumber,
                'call_sid' => $request->input('CallSid'),
                'session_id' => $request->input('Session'),
                'rejection_strategy' => $blacklist->rejection_strategy,
                'torment_room_id' => $tormentRoomId,
                'torment_duration' => $tormentDuration,
                'webhook_payload' => $request->all(),
                'source_ip' => $request->ip(),
                'blocked_at' => now(),
            ]);

            // Increment statistics
            $blacklist->incrementBlockedCount();
        } catch (\Exception $e) {
            Log::error('Failed to log blocked call', [
                'error' => $e->getMessage(),
                'blacklist_id' => $blacklist->id,
            ]);
        }
    }
}
