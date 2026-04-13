<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * Campaign Query Service
 *
 * Handles campaign queries, filtering, and status checks for the dialer worker.
 * All methods bypass organization scope for worker API access.
 */
class CampaignQueryService
{
    /**
     * Get all active campaigns that should be running.
     *
     * Returns campaigns that are:
     * - status = 'active'
     * - Within date range
     * - Within schedule (if current time falls within active hours)
     *
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getActiveRunnableCampaigns(): Collection
    {
        return OrganizationScope::bypass(function () {
            return AutoDialerCampaign::with(['organization.cloudonixSettings', 'callerIds'])
                ->where('status', CampaignStatus::ACTIVE)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->get()
                ->filter(fn ($campaign) => $campaign->isRunnable())
                ->values();
        });
    }

    /**
     * Find a campaign by ID (bypasses organization scope).
     */
    public function findById(int $campaignId): ?AutoDialerCampaign
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaignId)
        );
    }

    /**
     * Find a campaign with specific relationships loaded.
     *
     * @param  array<string>  $relations
     */
    public function findWithRelations(int $campaignId, array $relations): ?AutoDialerCampaign
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCampaign::with($relations)->find($campaignId)
        );
    }

    /**
     * Count active campaigns.
     */
    public function countActive(): int
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)->count()
        );
    }

    /**
     * Check if a campaign exists and is active.
     */
    public function isActive(int $campaignId): bool
    {
        $campaign = $this->findById($campaignId);

        return $campaign !== null && $campaign->status === CampaignStatus::ACTIVE;
    }

    /**
     * Get campaigns by status.
     *
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getByStatus(CampaignStatus $status): Collection
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCampaign::where('status', $status)->get()
        );
    }

    /**
     * Get campaigns that need to be auto-started.
     *
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getAutoStartCandidates(): Collection
    {
        return OrganizationScope::bypass(function () {
            return AutoDialerCampaign::where('auto_start', true)
                ->where('status', CampaignStatus::DRAFT)
                ->whereHas('list', function ($query) {
                    $query->where('status', 'ready');
                })
                ->get();
        });
    }

    /**
     * Get campaign IDs for a specific organization.
     *
     * @return array<int>
     */
    public function getCampaignIdsForOrganization(int $organizationId): array
    {
        return OrganizationScope::bypass(
            fn () => AutoDialerCampaign::where('organization_id', $organizationId)
                ->pluck('id')
                ->toArray()
        );
    }

    /**
     * Check if campaign is within its scheduled time.
     */
    public function isWithinSchedule(AutoDialerCampaign $campaign): bool
    {
        return $campaign->isRunnable();
    }

    /**
     * Get campaigns that are currently runnable (active + in schedule).
     *
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getRunnableCampaigns(): Collection
    {
        return $this->getActiveRunnableCampaigns()
            ->filter(fn ($campaign) => $this->isWithinSchedule($campaign))
            ->values();
    }
}
