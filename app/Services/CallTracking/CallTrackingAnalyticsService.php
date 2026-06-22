<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CallTrackingAnalyticsService
{
    /**
     * Get aggregate KPIs for the filtered sessions.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     * } $filters
     * @return array{
     *     total_calls: int,
     *     unique_callers: int,
     *     answered_calls: int,
     *     missed_calls: int,
     *     average_duration: float,
     *     conversions: int,
     *     conversion_rate: float,
     * }
     */
    public function getKpis(array $filters): array
    {
        $result = $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw('COUNT(DISTINCT caller_number) as unique_callers')
            ->selectRaw('SUM(is_answered) as answered_calls')
            ->selectRaw('COUNT(*) - SUM(is_answered) as missed_calls')
            ->selectRaw('AVG(duration) as average_duration')
            ->selectRaw('SUM(is_converted) as conversions')
            ->first();

        $totalCalls = (int) ($result?->total_calls ?? 0);
        $conversions = (int) ($result?->conversions ?? 0);

        return [
            'total_calls' => $totalCalls,
            'unique_callers' => (int) ($result?->unique_callers ?? 0),
            'answered_calls' => (int) ($result?->answered_calls ?? 0),
            'missed_calls' => (int) ($result?->missed_calls ?? 0),
            'average_duration' => (float) ($result?->average_duration ?? 0.0),
            'conversions' => $conversions,
            'conversion_rate' => $totalCalls > 0 ? ($conversions / $totalCalls) * 100 : 0.0,
        ];
    }

    /**
     * Get time-series rows grouped by day/week/month.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     *     group_by?: 'day'|'week'|'month',
     * } $filters
     * @return array<int, array{date_key: string, calls: int, conversions: int}>
     */
    public function getTimeSeries(array $filters): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $column = $this->dateGroupColumn($groupBy, DB::getDriverName());

        $rows = $this->baseQuery($filters)
            ->selectRaw("{$column} as date_key")
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_converted) as conversions')
            ->groupBy(DB::raw($column))
            ->orderBy(DB::raw($column))
            ->get();

        return $rows->map(fn ($row) => [
            'date_key' => (string) $row->date_key,
            'calls' => (int) $row->calls,
            'conversions' => (int) $row->conversions,
        ])->all();
    }

    /**
     * Get top campaigns by call volume.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     * } $filters
     * @return array<int, array{campaign_id: int, campaign_name: string, calls: int, conversions: int}>
     */
    public function getTopCampaigns(array $filters, int $limit = 10): array
    {
        $rows = $this->baseQuery($filters)
            ->selectRaw('call_tracking_campaign_id as campaign_id')
            ->selectRaw('campaign_name')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_converted) as conversions')
            ->groupBy('call_tracking_campaign_id', 'campaign_name')
            ->orderByDesc('calls')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'campaign_id' => (int) $row->campaign_id,
            'campaign_name' => (string) $row->campaign_name,
            'calls' => (int) $row->calls,
            'conversions' => (int) $row->conversions,
        ])->all();
    }

    /**
     * Get top sources by call volume.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     * } $filters
     * @return array<int, array{source: string, calls: int, conversions: int}>
     */
    public function getTopSources(array $filters, int $limit = 10): array
    {
        $rows = $this->baseQuery($filters)
            ->selectRaw('source')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_converted) as conversions')
            ->groupBy('source')
            ->orderByDesc('calls')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'source' => (string) $row->source,
            'calls' => (int) $row->calls,
            'conversions' => (int) $row->conversions,
        ])->all();
    }

    /**
     * Get export rows grouped by date, campaign, source, and medium.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     *     group_by?: 'day'|'week'|'month',
     * } $filters
     * @return array<int, array{date: string, campaign_name: string, source: string, medium: string, calls: int, answered: int, missed: int, conversions: int, avg_duration: float}>
     */
    public function getExportRows(array $filters): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $column = $this->dateGroupColumn($groupBy, DB::getDriverName());

        $rows = $this->baseQuery($filters)
            ->selectRaw("{$column} as date")
            ->selectRaw('campaign_name')
            ->selectRaw('source')
            ->selectRaw('medium')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(is_answered) as answered')
            ->selectRaw('COUNT(*) - SUM(is_answered) as missed')
            ->selectRaw('SUM(is_converted) as conversions')
            ->selectRaw('AVG(duration) as avg_duration')
            ->groupBy(DB::raw($column), 'campaign_name', 'source', 'medium')
            ->orderBy(DB::raw($column))
            ->orderBy('campaign_name')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => (string) $row->date,
            'campaign_name' => (string) $row->campaign_name,
            'source' => (string) $row->source,
            'medium' => (string) $row->medium,
            'calls' => (int) $row->calls,
            'answered' => (int) $row->answered,
            'missed' => (int) $row->missed,
            'conversions' => (int) $row->conversions,
            'avg_duration' => (float) $row->avg_duration,
        ])->all();
    }

    /**
     * Build the base filtered query for sessions.
     *
     * @param array{
     *     organization_id: int,
     *     start_date: Carbon,
     *     end_date: Carbon,
     *     campaign_ids?: int[],
     *     sources?: string[],
     *     mediums?: string[],
     * } $filters
     */
    private function baseQuery(array $filters): Builder
    {
        return CallTrackingSession::withoutGlobalScope(OrganizationScope::class)
            ->forOrganization($filters['organization_id'])
            ->whereBetween('started_at', [$filters['start_date'], $filters['end_date']])
            ->when($filters['campaign_ids'] ?? [], fn ($q, $ids) => $q->whereIn('call_tracking_campaign_id', $ids))
            ->when($filters['sources'] ?? [], fn ($q, $sources) => $q->whereIn('source', $sources))
            ->when($filters['mediums'] ?? [], fn ($q, $mediums) => $q->whereIn('medium', $mediums));
    }

    /**
     * Get the SQL expression for grouping by date granularity.
     */
    private function dateGroupColumn(string $groupBy, string $driver): string
    {
        return match ($groupBy) {
            'week' => $driver === 'sqlite' ? "strftime('%Y-%W', started_at)" : "DATE_FORMAT(started_at, '%Y-%u')",
            'month' => $driver === 'sqlite' ? "strftime('%Y-%m', started_at)" : "DATE_FORMAT(started_at, '%Y-%m')",
            default => $driver === 'sqlite' ? "strftime('%Y-%m-%d', started_at)" : "DATE_FORMAT(started_at, '%Y-%m-%d')",
        };
    }
}
