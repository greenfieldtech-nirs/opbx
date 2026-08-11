<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CallDetailRecordResource;
use App\Models\CallDetailRecord;
use App\Models\User;
use App\Services\Supervisor\SupervisorFilterService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        $query->with('extension.user:id,name');
    }

    protected function afterShow(\Illuminate\Database\Eloquent\Model $model, Request $request): void
    {
        $model->loadMissing('extension.user:id,name');
    }

    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Supervisor-scoped filter: restrict CDRs to resources the Supervisor manages
        if ($request->boolean('supervisor') && $request->user()?->isSupervisor()) {
            $this->applySupervisorFilter($query, $request->user());
        }

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
     * Apply Supervisor resource filter to a CDR query.
     */
    private function applySupervisorFilter(Builder $query, User $user): void
    {
        if (! $user->isSupervisor()) {
            return;
        }

        $identifiers = app(SupervisorFilterService::class)->resourceIdentifiers($user);

        if (count($identifiers) === 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($identifiers): void {
            $q->whereIn('from', $identifiers)
                ->orWhereIn('to', $identifiers);
        });
    }

    /**
     * Export CDRs to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->getAuthenticatedUser();

        // Build query with same filters as index
        $query = CallDetailRecord::forOrganization($user->organization_id);

        // Apply Supervisor-scoped filter
        $this->applySupervisorFilter($query, $user);

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

        $response = new StreamedResponse(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // CSV Headers - using raw_cdr session data as requested
            fputcsv($handle, [
                'Timestamp',
                'From',
                'To',
                'Call Start Time',
                'Call Answer Time',
                'Call End Time',
                'Domain',
                'Session Token',
                'Duration (sec)',
                'Billable (sec)',
                'Status',
            ]);

            // Stream results in chunks to avoid memory issues
            $query->chunk(1000, function ($records) use ($handle) {
                foreach ($records as $record) {
                    // Extract session data from raw_cdr
                    $rawCdr = $record->raw_cdr ?? [];
                    $session = $rawCdr['session'] ?? [];

                    // Format timestamps from raw_cdr (milliseconds to seconds)
                    $callStartTime = isset($session['callStartTime']) && $session['callStartTime'] > 0
                        ? Carbon::createFromTimestampMs($session['callStartTime'])->format('Y-m-d H:i:s')
                        : '';
                    $callAnswerTime = isset($session['callAnswerTime']) && $session['callAnswerTime'] > 0
                        ? Carbon::createFromTimestampMs($session['callAnswerTime'])->format('Y-m-d H:i:s')
                        : '';
                    $callEndTime = isset($session['callEndTime']) && $session['callEndTime'] > 0
                        ? Carbon::createFromTimestampMs($session['callEndTime'])->format('Y-m-d H:i:s')
                        : '';

                    fputcsv($handle, [
                        $record->session_timestamp?->format('Y-m-d H:i:s'),
                        $record->from,
                        $record->to,
                        $callStartTime,
                        $callAnswerTime,
                        $callEndTime,
                        $record->domain,
                        $session['token'] ?? '',
                        $record->duration,
                        $record->billsec,
                        $session['status'] ?? $record->status,
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
            'total_calls' => $this->buildStatisticsQuery($user, $fromDate, $toDate)->count(),
            'total_duration' => $this->buildStatisticsQuery($user, $fromDate, $toDate)->sum('duration'),
            'total_billsec' => $this->buildStatisticsQuery($user, $fromDate, $toDate)->sum('billsec'),
            'average_duration' => $this->buildStatisticsQuery($user, $fromDate, $toDate)->where('duration', '>', 0)->avg('duration'),
            'total_cost' => $this->buildStatisticsQuery($user, $fromDate, $toDate)->sum('sell_cost'),
            'by_disposition' => $this->buildStatisticsQuery($user, $fromDate, $toDate)
                ->selectRaw('disposition, COUNT(*) as count')
                ->groupBy('disposition')
                ->pluck('count', 'disposition'),
        ];

        return response()->json($stats);
    }

    /**
     * Build the base CDR query for statistics, scoped to the organization,
     * date range, and Supervisor-assigned resources.
     */
    private function buildStatisticsQuery(User $user, string $fromDate, string $toDate): Builder
    {
        $query = CallDetailRecord::forOrganization($user->organization_id)
            ->whereDate('session_timestamp', '>=', $fromDate)
            ->whereDate('session_timestamp', '<=', $toDate);

        $this->applySupervisorFilter($query, $user);

        return $query;
    }
}
