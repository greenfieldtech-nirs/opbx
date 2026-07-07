<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\CallTracking\AnalyticsRequest;
use App\Http\Resources\CallTrackingAnalyticsResource;
use App\Services\CallTracking\CallTrackingAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call Tracking Analytics API controller.
 *
 * Provides aggregate KPIs, time-series, top campaigns/sources, and CSV export.
 */
class CallTrackingAnalyticsController extends Controller
{
    use ApiRequestHandler;

    /**
     * Display analytics aggregated for the authenticated user's organization.
     */
    public function index(AnalyticsRequest $request, CallTrackingAnalyticsService $service): JsonResponse
    {
        $filters = $this->buildFilters($request);

        $resource = new CallTrackingAnalyticsResource(
            $service->getKpis($filters),
            $service->getTimeSeries($filters),
            $service->getTopCampaigns($filters),
            $service->getTopSources($filters),
            $filters,
        );

        return response()->json($resource->toArray($request));
    }

    /**
     * Export analytics to CSV grouped by the requested granularity.
     */
    public function export(AnalyticsRequest $request, CallTrackingAnalyticsService $service): StreamedResponse
    {
        $filters = $this->buildFilters($request);
        $rows = $service->getExportRows($filters);

        $filename = 'call-tracking-analytics-'.now()->format('Y-m-d-His').'.csv';

        $response = new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'date',
                'campaign_name',
                'source',
                'medium',
                'calls',
                'answered',
                'missed',
                'conversions',
                'avg_duration',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['campaign_name'],
                    $row['source'],
                    $row['medium'],
                    $row['calls'],
                    $row['answered'],
                    $row['missed'],
                    $row['conversions'],
                    $row['avg_duration'],
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    /**
     * Build the filter array expected by the analytics service.
     *
     * @return array<string, mixed>
     */
    private function buildFilters(AnalyticsRequest $request): array
    {
        $user = $this->getAuthenticatedUser();
        $validated = $request->validated();

        $filters = [
            'organization_id' => (int) $user->organization_id,
            'start_date' => Carbon::parse($validated['start_date'])->startOfDay(),
            'end_date' => Carbon::parse($validated['end_date'])->endOfDay(),
            'group_by' => $validated['group_by'] ?? 'day',
        ];

        if (! empty($validated['campaign_ids'])) {
            $filters['campaign_ids'] = array_map('intval', $validated['campaign_ids']);
        }

        if (! empty($validated['sources'])) {
            $filters['sources'] = $validated['sources'];
        }

        if (! empty($validated['mediums'])) {
            $filters['mediums'] = $validated['mediums'];
        }

        return $filters;
    }
}
