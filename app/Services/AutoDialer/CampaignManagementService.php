<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campaign Management Service
 *
 * Handles campaign queries, filtering, searching, and retrieval operations for the API.
 */
class CampaignManagementService
{
    /**
     * List campaigns with filtering and pagination.
     *
     * @param  int  $organizationId  The organization ID to scope to
     * @param  array<string, mixed>  $filters  Optional filters (status, search, per_page)
     * @return LengthAwarePaginator<AutoDialerCampaign>
     */
    public function listCampaigns(int $organizationId, array $filters = []): LengthAwarePaginator
    {
        $query = AutoDialerCampaign::forOrganization($organizationId)
            ->with(['callerIds', 'lists']);

        // Apply status filter
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply search filter
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where('name', 'like', "%{$search}%");
        }

        $perPage = $filters['per_page'] ?? 25;

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get a single campaign with relationships loaded.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to load
     * @param  array<int, string>  $relations  Relations to eager load
     */
    public function getCampaign(AutoDialerCampaign $campaign, array $relations = ['callerIds', 'list']): AutoDialerCampaign
    {
        if (! empty($relations)) {
            $campaign->load($relations);
        }

        return $campaign;
    }

    /**
     * Get destinations for a campaign with optional filtering.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign to get destinations for
     * @param  array<string, mixed>  $filters  Optional filters (status, per_page)
     * @return LengthAwarePaginator<AutoDialerDestination>
     */
    public function getDestinations(AutoDialerCampaign $campaign, array $filters = []): LengthAwarePaginator
    {
        $query = $campaign->destinations();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = $filters['per_page'] ?? 50;

        return $query->paginate($perPage);
    }

    /**
     * Get active campaigns for an organization.
     *
     * @param  int  $organizationId  The organization ID
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getActiveCampaigns(int $organizationId): Collection
    {
        return AutoDialerCampaign::forOrganization($organizationId)
            ->where('status', CampaignStatus::ACTIVE)
            ->get();
    }

    /**
     * Get campaigns by status.
     *
     * @param  int  $organizationId  The organization ID
     * @param  CampaignStatus  $status  The status to filter by
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getCampaignsByStatus(int $organizationId, CampaignStatus $status): Collection
    {
        return AutoDialerCampaign::forOrganization($organizationId)
            ->where('status', $status)
            ->get();
    }

    /**
     * Search campaigns by name.
     *
     * @param  int  $organizationId  The organization ID
     * @param  string  $search  The search term
     * @param  int  $limit  Maximum results to return
     * @return Collection<int, AutoDialerCampaign>
     */
    public function searchByName(int $organizationId, string $search, int $limit = 25): Collection
    {
        return AutoDialerCampaign::forOrganization($organizationId)
            ->where('name', 'like', "%{$search}%")
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get campaigns eligible for auto-start.
     *
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getAutoStartEligibleCampaigns(): Collection
    {
        return AutoDialerCampaign::where('auto_start', true)
            ->where('status', CampaignStatus::DRAFT)
            ->whereHas('list', function ($query) {
                $query->where('status', 'ready');
            })
            ->get();
    }

    /**
     * Get runnable campaigns (active and within schedule).
     *
     * @param  int|null  $organizationId  Optional organization ID to scope to
     * @return Collection<int, AutoDialerCampaign>
     */
    public function getRunnableCampaigns(?int $organizationId = null): Collection
    {
        $query = AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now());

        if ($organizationId !== null) {
            $query->forOrganization($organizationId);
        }

        return $query->get();
    }

    /**
     * Check if a campaign name is unique within an organization.
     *
     * @param  string  $name  The name to check
     * @param  int  $organizationId  The organization ID
     * @param  int|null  $excludeCampaignId  Optional campaign ID to exclude
     */
    public function isNameUnique(string $name, int $organizationId, ?int $excludeCampaignId = null): bool
    {
        $query = AutoDialerCampaign::forOrganization($organizationId)
            ->where('name', $name);

        if ($excludeCampaignId !== null) {
            $query->where('id', '!=', $excludeCampaignId);
        }

        return ! $query->exists();
    }

    /**
     * Get campaign counts by status for an organization.
     *
     * @param  int  $organizationId  The organization ID
     * @return array<string, int>
     */
    public function getCampaignCountsByStatus(int $organizationId): array
    {
        $counts = AutoDialerCampaign::forOrganization($organizationId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'draft' => $counts['draft'] ?? 0,
            'active' => $counts['active'] ?? 0,
            'paused' => $counts['paused'] ?? 0,
            'completed' => $counts['completed'] ?? 0,
            'archived' => $counts['archived'] ?? 0,
        ];
    }

    /**
     * Get paginated results as array with metadata.
     *
     * @param  LengthAwarePaginator<AutoDialerCampaign>  $paginator  The paginator
     * @return array<string, mixed>
     */
    public function formatPaginatedResponse(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
