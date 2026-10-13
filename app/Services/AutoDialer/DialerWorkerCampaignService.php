<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Dialer Worker Campaign Service
 *
 * Handles campaign operations specific to the dialer worker API.
 * Manages pause/resume, state persistence, and retry logic.
 */
class DialerWorkerCampaignService
{
    public function __construct(
        private readonly CallSessionService $callSessionService,
    ) {}

    /**
     * Pause a campaign (used by circuit breaker or schedule).
     *
     * @param  array<string, mixed>  $data  Contains reason, paused_by, resume_at
     * @return array<string, mixed>
     */
    public function pauseCampaign(AutoDialerCampaign $campaign, array $data): array
    {
        OrganizationScope::bypass(function () use ($campaign, $data) {
            $campaign->update([
                'status' => CampaignStatus::PAUSED,
                'pause_reason' => $data['reason'],
                'resume_at' => $data['resume_at'] ?? null,
            ]);
        });

        // Store pause info in cache
        Cache::put(
            "campaign_pause:{$campaign->id}",
            [
                'reason' => $data['reason'],
                'paused_by' => $data['paused_by'],
                'resume_at' => $data['resume_at'] ?? null,
                'paused_at' => now()->toIso8601String(),
            ],
            now()->addHours(24)
        );

        // Mark in-flight sessions as failed
        $this->callSessionService->markInFlightAsFailed($campaign->id);

        // Reset pending calls counter
        OrganizationScope::bypass(fn () => $campaign->update(['pending_calls' => 0]));

        Log::info('DialerWorker: Campaign paused', [
            'campaign_id' => $campaign->id,
            'reason' => $data['reason'],
            'paused_by' => $data['paused_by'],
        ]);

        return [
            'campaign_id' => $campaign->id,
            'status' => 'paused',
            'reason' => $data['reason'],
        ];
    }

    /**
     * Resume a paused campaign.
     */
    public function resumeCampaign(AutoDialerCampaign $campaign): array
    {
        OrganizationScope::bypass(function () use ($campaign) {
            $campaign->update([
                'status' => CampaignStatus::ACTIVE,
                'pause_reason' => null,
                'resume_at' => null,
            ]);
        });

        // Clear pause info from cache
        Cache::forget("campaign_pause:{$campaign->id}");

        Log::info('DialerWorker: Campaign resumed', [
            'campaign_id' => $campaign->id,
        ]);

        return [
            'campaign_id' => $campaign->id,
            'status' => 'active',
        ];
    }

    /**
     * Persist worker state for failure recovery.
     *
     * @param  array<string, mixed>  $state
     */
    public function persistWorkerState(string $workerId, array $state): void
    {
        Cache::put(
            "worker_state:{$workerId}",
            $state,
            now()->addMinutes(10)
        );
    }

    /**
     * Get persisted worker state.
     *
     * @return array<string, mixed>|null
     */
    public function getWorkerState(string $workerId): ?array
    {
        return Cache::get("worker_state:{$workerId}");
    }

    /**
     * Check if campaign can be paused.
     */
    public function canPause(AutoDialerCampaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::ACTIVE;
    }

    /**
     * Check if campaign can be resumed.
     */
    public function canResume(AutoDialerCampaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::PAUSED;
    }

    /**
     * Get campaign pause info from cache.
     *
     * @return array<string, mixed>|null
     */
    public function getPauseInfo(int $campaignId): ?array
    {
        return Cache::get("campaign_pause:{$campaignId}");
    }

    /**
     * Clear campaign pause info from cache.
     */
    public function clearPauseInfo(int $campaignId): void
    {
        Cache::forget("campaign_pause:{$campaignId}");
    }

    /**
     * Increment pending calls counter.
     */
    public function incrementPendingCalls(AutoDialerCampaign $campaign): void
    {
        OrganizationScope::bypass(fn () => $campaign->increment('pending_calls'));
    }

    /**
     * Decrement pending calls counter.
     */
    public function decrementPendingCalls(AutoDialerCampaign $campaign): void
    {
        OrganizationScope::bypass(function () use ($campaign) {
            if ($campaign->pending_calls > 0) {
                $campaign->decrement('pending_calls');
            }
        });
    }

    /**
     * Increment completed calls counter.
     */
    public function incrementCompletedCalls(AutoDialerCampaign $campaign): void
    {
        OrganizationScope::bypass(fn () => $campaign->increment('completed_calls'));
    }

    /**
     * Increment failed calls counter.
     */
    public function incrementFailedCalls(AutoDialerCampaign $campaign): void
    {
        OrganizationScope::bypass(fn () => $campaign->increment('failed_calls'));
    }

    /**
     * Normalise a Cloudonix / CDR disposition ("NO ANSWER", "ANSWER", "CANCEL"...) to our vocabulary.
     */
    public function normalizeDisposition(?string $disposition): string
    {
        $value = str_replace([' ', '_'], '-', strtolower(trim((string) $disposition)));

        return match ($value) {
            'answer' => 'answered',
            'noanswer' => 'no-answer',
            'cancel' => 'cancelled',
            default => $value,
        };
    }

    /**
     * The ONE place that decides what happens to a destination after a call ends.
     *
     * answered/completed -> COMPLETED. busy/no-answer -> PENDING with next_retry_at when the
     * campaign has "retry on busy / no-answer" on and attempts remain, otherwise FAILED.
     * cancelled, failed, congestion and anything unknown -> FAILED (never retried).
     * The gap grows: retry_delay_minutes x 2^(attempt-1), capped at 24 h.
     *
     * @return array{status: DestinationStatus, next_retry_at: ?Carbon}
     */
    public function decideOutcome(AutoDialerCampaign $campaign, int $dialAttempts, ?string $disposition): array
    {
        $disposition = $this->normalizeDisposition($disposition);

        if (in_array($disposition, ['answered', 'completed'], true)) {
            return ['status' => DestinationStatus::COMPLETED, 'next_retry_at' => null];
        }

        if ($campaign->retry_on_busy_no_answer
            && in_array($disposition, ['busy', 'no-answer'], true)
            && $dialAttempts < $campaign->max_dial_attempts) {
            return [
                'status' => DestinationStatus::PENDING,
                'next_retry_at' => now()->addMinutes($this->retryDelayMinutes($campaign, $dialAttempts)),
            ];
        }

        return ['status' => DestinationStatus::FAILED, 'next_retry_at' => null];
    }

    /** Busy / no-answer retry gap in minutes after the given attempt: base x 2^(attempt-1), max 1440. */
    public function retryDelayMinutes(AutoDialerCampaign $campaign, int $dialAttempts): int
    {
        $base = max(1, (int) $campaign->retry_delay_minutes);

        return (int) min(1440, $base * (2 ** min(max(1, $dialAttempts) - 1, 20)));
    }
}
