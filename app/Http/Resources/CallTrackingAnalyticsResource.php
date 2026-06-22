<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Tracking Analytics API Resource.
 */
class CallTrackingAnalyticsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $kpis
     * @param  array<int, array<string, mixed>>  $timeSeries
     * @param  array<int, array<string, mixed>>  $topCampaigns
     * @param  array<int, array<string, mixed>>  $topSources
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public array $kpis,
        public array $timeSeries,
        public array $topCampaigns,
        public array $topSources,
        public array $filters,
    ) {
        parent::__construct(null);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kpis' => $this->kpis,
            'time_series' => $this->timeSeries,
            'top_campaigns' => $this->topCampaigns,
            'top_sources' => $this->topSources,
            'filters' => [
                'start_date' => $this->filters['start_date']->toDateString(),
                'end_date' => $this->filters['end_date']->toDateString(),
                'group_by' => $this->filters['group_by'],
            ],
        ];
    }
}
