<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * Call Session Service
 *
 * Handles call session management, state handling, and session queries
 * for the dialer worker.
 */
class CallSessionService
{
    /**
     * Create a new call session.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSession(AutoDialerCampaign $campaign, AutoDialerDestination $destination, array $data): AutoDialerCallSession
    {
        return OrganizationScope::bypass(function () use ($campaign, $destination, $data) {
            return AutoDialerCallSession::create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'session_token' => $data['session_token'] ?? 'sess-'.uniqid(),
                'call_id' => $data['call_id'] ?? null,
                'phone_number' => $data['phone_number'],
                'caller_id' => $data['caller_id'] ?? $campaign->caller_id,
                'caller_did_id' => $data['caller_did_id'] ?? null,
                'worker_id' => $data['worker_id'],
                'status' => 'initiated',
                'initiated_at' => $data['initiated_at'],
            ]);
        });
    }

    /**
     * Find a session by ID.
     */
    public function findById(int $sessionId): ?AutoDialerCallSession
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::find($sessionId)
        );
    }

    /**
     * Find a session with campaign and destination loaded.
     */
    public function findWithRelations(int $sessionId): ?AutoDialerCallSession
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::with(['campaign', 'destination'])->find($sessionId)
        );
    }

    /**
     * Update session status and related fields.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSession(AutoDialerCallSession $session, array $data): void
    {
        OrganizationScope::bypass(function () use ($session, $data) {
            $session->update($data);
        });
    }

    /**
     * Set final disposition for a session.
     *
     * @param  array<string, mixed>  $data
     */
    public function setDisposition(AutoDialerCallSession $session, array $data): void
    {
        OrganizationScope::bypass(function () use ($session, $data) {
            $session->update([
                'disposition' => $data['disposition'],
                'duration' => $data['duration'] ?? 0,
                'billsec' => $data['billsec'] ?? 0,
                'completed_at' => $data['completed_at'] ?? now(),
                'status' => $data['status'] ?? 'completed',
            ]);
        });
    }

    /**
     * Count active sessions (initiated, ringing, answered).
     */
    public function countActive(): int
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::whereIn('status', ['initiated', 'ringing', 'answered'])->count()
        );
    }

    /**
     * Get active sessions for a campaign.
     *
     * @return Collection<int, AutoDialerCallSession>
     */
    public function getActiveForCampaign(int $campaignId): Collection
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereIn('status', ['initiated', 'ringing', 'answered'])
                ->get()
        );
    }

    /**
     * Mark sessions as failed for a campaign (used when pausing).
     *
     * @return int Number of sessions updated
     */
    public function markInFlightAsFailed(int $campaignId): int
    {
        return OrganizationScope::bypass(function () use ($campaignId) {
            return AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereIn('status', ['initiated', 'ringing', 'answered'])
                ->update([
                    'status' => 'failed',
                    'disposition' => 'cancelled',
                    'completed_at' => now(),
                ]);
        });
    }

    /**
     * Get session by call ID.
     */
    public function findByCallId(string $callId): ?AutoDialerCallSession
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::where('call_id', $callId)->first()
        );
    }

    /**
     * Get session by session token.
     */
    public function findBySessionToken(string $sessionToken): ?AutoDialerCallSession
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::where('session_token', $sessionToken)->first()
        );
    }

    /**
     * Complete a session with final disposition.
     */
    public function completeSession(
        AutoDialerCallSession $session,
        string $disposition,
        ?int $duration = null,
        ?int $billsec = null
    ): void {
        OrganizationScope::bypass(function () use ($session, $disposition, $duration, $billsec) {
            $session->update([
                'status' => 'completed',
                'disposition' => $disposition,
                'duration' => $duration ?? 0,
                'billsec' => $billsec ?? 0,
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * Fail a session.
     */
    public function failSession(AutoDialerCallSession $session, string $reason): void
    {
        OrganizationScope::bypass(function () use ($session, $reason) {
            $session->update([
                'status' => 'failed',
                'disposition' => $reason,
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * Get recent sessions for a campaign.
     *
     * @return Collection<int, AutoDialerCallSession>
     */
    public function getRecentForCampaign(int $campaignId, int $minutes = 5): Collection
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCallSession::where('campaign_id', $campaignId)
                ->where('initiated_at', '>=', now()->subMinutes($minutes))
                ->orderBy('initiated_at', 'desc')
                ->get()
        );
    }

    /**
     * Count sessions by disposition for a campaign.
     *
     * @return array<string, int>
     */
    public function getDispositionCounts(int $campaignId): array
    {
        return OrganizationScope::bypass(function () use ($campaignId) {
            return AutoDialerCallSession::where('campaign_id', $campaignId)
                ->whereNotNull('disposition')
                ->selectRaw('disposition, COUNT(*) as count')
                ->groupBy('disposition')
                ->pluck('count', 'disposition')
                ->toArray();
        });
    }
}
