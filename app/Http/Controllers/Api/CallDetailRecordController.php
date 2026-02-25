<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CallDetailRecordResource;
use App\Models\CallDetailRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Call Detail Record (CDR) API controller (read-only).
 *
 * Provides access to CDR data with filtering and pagination.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class CallDetailRecordController extends AbstractApiCrudController
{
    protected function getModelClass(): string
    {
        return CallDetailRecord::class;
    }

    protected function getResourceClass(): string
    {
        return CallDetailRecordResource::class;
    }

    protected function getAllowedFilters(): array
    {
        return ['from', 'to', 'disposition', 'from_date', 'to_date'];
    }

    protected function getAllowedSortFields(): array
    {
        return ['session_timestamp', 'from', 'to', 'duration', 'billsec', 'disposition'];
    }

    protected function getDefaultSortField(): string
    {
        return 'session_timestamp';
    }

    protected function getDefaultSortOrder(): string
    {
        return 'desc';
    }

    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Filter by caller number (from)
        if ($request->filled('from')) {
            $query->where('from', 'like', '%'.$request->input('from').'%');
        }

        // Filter by called number (to)
        if ($request->filled('to')) {
            $query->where('to', 'like', '%'.$request->input('to').'%');
        }

        // Filter by disposition
        if ($request->filled('disposition')) {
            $query->where('disposition', $request->input('disposition'));
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('session_timestamp', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('session_timestamp', '<=', $request->input('to_date'));
        }
    }

    /**
     * Export CDRs to CSV.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $this->getAuthenticatedUser();

        // Build query with same filters as index
        $query = CallDetailRecord::forOrganization($user->organization_id);

        // Apply filters
        if ($request->filled('from')) {
            $query->where('from', 'like', '%'.$request->input('from').'%');
        }

        if ($request->filled('to')) {
            $query->where('to', 'like', '%'.$request->input('to').'%');
        }

        if ($request->filled('disposition')) {
            $query->where('disposition', $request->input('disposition'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('session_timestamp', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('session_timestamp', '<=', $request->input('to_date'));
        }

        // Order by timestamp desc
        $query->orderBy('session_timestamp', 'desc');

        $filename = 'call-detail-records-'.now()->format('Y-m-d-His').'.csv';

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Session Timestamp',
                'Call ID',
                'From',
                'To',
                'Disposition',
                'Duration (sec)',
                'Billable (sec)',
                'Domain',
                'Subscriber',
                'Application',
                'Rated Cost',
                'Sell Cost',
                'Status',
            ]);

            // Stream results in chunks to avoid memory issues
            $query->chunk(1000, function ($records) use ($handle) {
                foreach ($records as $record) {
                    fputcsv($handle, [
                        $record->session_timestamp?->format('Y-m-d H:i:s'),
                        $record->call_id,
                        $record->from,
                        $record->to,
                        $record->disposition,
                        $record->duration,
                        $record->billsec,
                        $record->domain,
                        $record->subscriber,
                        $record->application,
                        $record->rated_cost,
                        $record->sell_cost,
                        $record->status,
                    ]);
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    /**
     * Get CDR statistics for the organization.
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $fromDate = $request->input('from_date', now()->subDays(30)->toDateString());
        $toDate = $request->input('to_date', now()->toDateString());

        $stats = [
            'total_calls' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->count(),
            'total_duration' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('duration'),
            'total_billsec' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('billsec'),
            'average_duration' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->where('duration', '>', 0)
                ->avg('duration'),
            'total_cost' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->sum('sell_cost'),
            'by_disposition' => CallDetailRecord::forOrganization($user->organization_id)
                ->whereDate('session_timestamp', '>=', $fromDate)
                ->whereDate('session_timestamp', '<=', $toDate)
                ->selectRaw('disposition, COUNT(*) as count')
                ->groupBy('disposition')
                ->pluck('count', 'disposition'),
        ];

        return response()->json($stats);
    }
}
