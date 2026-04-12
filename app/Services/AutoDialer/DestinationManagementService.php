<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * Destination Management Service
 *
 * Handles destination queries and updates for the dialer worker.
 * All methods bypass organization scope for worker API access.
 */
class DestinationManagementService
{
    /**
     * Get list IDs for a campaign.
     *
     * @return array<int>
     */
    public function getListIdsForCampaign(int $campaignId): array
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerList::where('campaign_id', $campaignId)
                ->pluck('id')
                ->toArray()
        );
    }

    /**
     * Get pending destinations for a campaign.
     *
     * @return Collection<int, AutoDialerDestination>
     */
    public function getPendingDestinations(int $campaignId, int $limit = 50): Collection
    {
        $listIds = $this->getListIdsForCampaign($campaignId);

        if (empty($listIds)) {
            return collect();
        }

        $campaign = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaignId)
        );

        if (! $campaign) {
            return collect();
        }

        return OrganizationScope::bypass(function () use ($listIds, $campaign, $limit) {
            return AutoDialerDestination::whereIn('list_id', $listIds)
                ->where('status', DestinationStatus::PENDING)
                ->where('dial_attempts', '<', $campaign->max_dial_attempts)
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get retry destinations for a campaign.
     *
     * Returns destinations with retryable dispositions
     * where next retry time has been reached.
     *
     * @return Collection<int, AutoDialerDestination>
     */
    public function getRetryDestinations(int $campaignId, int $limit = 50): Collection
    {
        $listIds = $this->getListIdsForCampaign($campaignId);

        if (empty($listIds)) {
            return collect();
        }

        $campaign = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaignId)
        );

        if (! $campaign) {
            return collect();
        }

        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

        return OrganizationScope::bypass(function () use ($listIds, $campaign, $limit, $retryableDispositions) {
            return AutoDialerDestination::whereIn('list_id', $listIds)
                ->whereIn('last_disposition', $retryableDispositions)
                ->where('dial_attempts', '<', $campaign->max_dial_attempts)
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->orderBy('next_retry_at', 'asc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Find a destination by ID.
     */
    public function findById(int $destinationId): ?AutoDialerDestination
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerDestination::find($destinationId)
        );
    }

    /**
     * Update destination status to dialing.
     */
    public function markAsDialing(AutoDialerDestination $destination): void
    {
        OrganizationScope::bypass(function () use ($destination) {
            $destination->update([
                'status' => DestinationStatus::DIALING,
                'dial_attempts' => $destination->dial_attempts + 1,
                'last_dialed_at' => now(),
            ]);
        });
    }

    /**
     * Update destination with disposition result.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDisposition(AutoDialerDestination $destination, array $data): void
    {
        OrganizationScope::bypass(function () use ($destination, $data) {
            $destination->update([
                'last_disposition' => $data['disposition'] ?? null,
                'duration' => $data['duration'] ?? 0,
                'billsec' => $data['billsec'] ?? 0,
                'status' => $data['status'],
                'next_retry_at' => $data['next_retry_at'] ?? null,
            ]);
        });
    }

    /**
     * Mark destination as completed.
     */
    public function markAsCompleted(AutoDialerDestination $destination, ?string $disposition = null, ?int $duration = null, ?int $billsec = null): void
    {
        OrganizationScope::bypass(function () use ($destination, $disposition, $duration, $billsec) {
            $destination->update([
                'status' => DestinationStatus::COMPLETED,
                'last_disposition' => $disposition ?? 'completed',
                'duration' => $duration ?? 0,
                'billsec' => $billsec ?? 0,
            ]);
        });
    }

    /**
     * Mark destination as failed.
     */
    public function markAsFailed(AutoDialerDestination $destination, ?string $disposition = null, ?int $duration = null, ?int $billsec = null): void
    {
        OrganizationScope::bypass(function () use ($destination, $disposition, $duration, $billsec) {
            $destination->update([
                'status' => DestinationStatus::FAILED,
                'last_disposition' => $disposition ?? 'failed',
                'duration' => $duration ?? 0,
                'billsec' => $billsec ?? 0,
            ]);
        });
    }

    /**
     * Mark destination as pending for retry.
     */
    public function scheduleRetry(AutoDialerDestination $destination, string $nextRetryAt, ?string $disposition = null): void
    {
        OrganizationScope::bypass(function () use ($destination, $nextRetryAt, $disposition) {
            $destination->update([
                'status' => DestinationStatus::PENDING,
                'next_retry_at' => $nextRetryAt,
                'last_disposition' => $disposition ?? $destination->last_disposition,
            ]);
        });
    }

    /**
     * Count pending destinations for a campaign.
     */
    public function countPending(int $campaignId): int
    {
        $listIds = $this->getListIdsForCampaign($campaignId);

        if (empty($listIds)) {
            return 0;
        }

        return OrganizationScope::bypass(
            fn () => AutoDialerDestination::whereIn('list_id', $listIds)
                ->where('status', DestinationStatus::PENDING)
                ->count()
        );
    }

    /**
     * Count destinations ready for retry.
     */
    public function countRetryReady(int $campaignId): int
    {
        $listIds = $this->getListIdsForCampaign($campaignId);

        if (empty($listIds)) {
            return 0;
        }

        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

        return OrganizationScope::bypass(
            fn () => AutoDialerDestination::whereIn('list_id', $listIds)
                ->whereIn('last_disposition', $retryableDispositions)
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->count()
        );
    }

    /**
     * Count total destinations for a campaign.
     */
    public function countTotal(int $campaignId): int
    {
        $listIds = $this->getListIdsForCampaign($campaignId);

        if (empty($listIds)) {
            return 0;
        }

        return OrganizationScope::bypass(
            fn () => AutoDialerDestination::whereIn('list_id', $listIds)->count()
        );
    }

    /**
     * Get retry queue depth across all campaigns.
     *
     * Used for health checks.
     */
    public function countGlobalRetryQueue(): int
    {
        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

        return OrganizationScope::bypass(
            fn () => AutoDialerDestination::whereIn('last_disposition', $retryableDispositions)
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->count()
        );
    }

    /**
     * Check if destination has reached max dial attempts.
     */
    public function hasReachedMaxAttempts(AutoDialerDestination $destination, int $maxAttempts): bool
    {
        return $destination->dial_attempts >= $maxAttempts;
    }

    /**
     * Check if destination is ready for retry.
     */
    public function isReadyForRetry(AutoDialerDestination $destination): bool
    {
        if ($destination->next_retry_at === null) {
            return true;
        }

        return now()->gte($destination->next_retry_at);
    }
}
