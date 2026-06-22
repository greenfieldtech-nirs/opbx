<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'version_number' => $this->version_number,
            'is_latest_version' => $this->is_latest_version,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),

            // Usage info
            'campaign_id' => $this->campaign_id,
            'used_by_campaign_id' => $this->used_by_campaign_id,
            'used_at' => $this->used_at?->format('Y-m-d H:i:s'),

            // Statistics
            'statistics' => [
                'total_rows' => $this->total_rows,
                'valid_rows' => $this->valid_rows,
                'invalid_rows' => $this->invalid_rows,
            ],

            // Versioning
            'parent_list_id' => $this->parent_list_id,
            'has_versions' => $this->when(
                $request->routeIs('*versions*'),
                false,
                fn () => $this->versions()->exists()
            ),

            // Flags
            'can_archive' => $this->canBeArchived(),
            'can_assign' => $this->status->canAssign(),
            'can_upload' => $this->status->canUpload(),
            'can_copy' => $this->status->canCopy(),
            'can_delete' => $this->canDelete(),
            'can_unassign' => $this->canUnassign(),

            // Timestamps
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'processed_at' => $this->processed_at?->format('Y-m-d H:i:s'),
            'archived_at' => $this->archived_at?->format('Y-m-d H:i:s'),

            // Relations
            'campaign' => $this->whenLoaded('campaign', fn () => [
                'id' => $this->campaign->id,
                'name' => $this->campaign->name,
            ]),
            'used_by_campaign' => $this->whenLoaded('usedByCampaign', fn () => [
                'id' => $this->usedByCampaign->id,
                'name' => $this->usedByCampaign->name,
            ]),
        ];
    }
}
