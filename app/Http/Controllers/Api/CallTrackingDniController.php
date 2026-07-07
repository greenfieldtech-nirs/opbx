<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CallTrackingCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\DniSwapRequest;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;

/**
 * Public DNI swap endpoint for call tracking.
 *
 * Returns the best matching tracking phone number for a visitor based on
 * UTM parameters and the organization's active call tracking configuration.
 */
class CallTrackingDniController extends Controller
{
    /**
     * Swap the displayed phone number based on UTM campaign attribution.
     */
    public function swap(DniSwapRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $organizationId = (int) $validated['organization_id'];
        $utmSource = $validated['utm_source'] ?? null;
        $utmMedium = $validated['utm_medium'] ?? null;
        $utmCampaign = $validated['utm_campaign'] ?? null;
        $defaultNumber = $validated['default_number'] ?? null;

        $campaign = null;
        $number = null;

        if (is_string($utmSource) && is_string($utmMedium)) {
            $campaign = $this->findMatchingCampaign(
                $organizationId,
                $utmSource,
                $utmMedium,
                $utmCampaign
            );

            if ($campaign !== null) {
                $number = $this->findActiveNumberForCampaign($organizationId, $campaign->id);
            }
        }

        if ($number === null) {
            $number = $this->findFirstActiveOrganizationNumber($organizationId);
        }

        return response()->json([
            'tracking_number' => $number?->did->phone_number ?? $defaultNumber,
            'campaign_id' => $campaign?->id,
            'campaign_name' => $campaign?->name,
            'source' => $campaign?->source,
            'medium' => $campaign?->medium,
        ]);
    }

    /**
     * Find the first active campaign matching the provided UTM parameters.
     */
    private function findMatchingCampaign(
        int $organizationId,
        string $utmSource,
        string $utmMedium,
        ?string $utmCampaign
    ): ?CallTrackingCampaign {
        return OrganizationScope::bypass(function () use (
            $organizationId,
            $utmSource,
            $utmMedium,
            $utmCampaign
        ): ?CallTrackingCampaign {
            $query = CallTrackingCampaign::query()
                ->forOrganization($organizationId)
                ->where('status', CallTrackingCampaignStatus::ACTIVE)
                ->whereRaw('LOWER(source) = LOWER(?)', [$utmSource])
                ->whereRaw('LOWER(medium) = LOWER(?)', [$utmMedium]);

            if (is_string($utmCampaign)) {
                $query->whereRaw('LOWER(name) LIKE LOWER(?)', ['%'.$utmCampaign.'%']);
            }

            return $query->first();
        });
    }

    /**
     * Find the first active tracking number assigned to a campaign.
     */
    private function findActiveNumberForCampaign(int $organizationId, int $campaignId): ?CallTrackingNumber
    {
        return OrganizationScope::bypass(function () use ($organizationId, $campaignId): ?CallTrackingNumber {
            return CallTrackingNumber::query()
                ->forOrganization($organizationId)
                ->where('status', CallTrackingCampaignStatus::ACTIVE)
                ->where('call_tracking_campaign_id', $campaignId)
                ->with('did')
                ->first();
        });
    }

    /**
     * Find the first active tracking number for an organization.
     */
    private function findFirstActiveOrganizationNumber(int $organizationId): ?CallTrackingNumber
    {
        return OrganizationScope::bypass(function () use ($organizationId): ?CallTrackingNumber {
            return CallTrackingNumber::query()
                ->forOrganization($organizationId)
                ->where('status', CallTrackingCampaignStatus::ACTIVE)
                ->with('did')
                ->first();
        });
    }
}
