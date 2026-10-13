<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CallDetailRecordResource;
use App\Models\ApiKey;
use App\Models\ArchivedCallDetailRecord;
use App\Models\CallDetailRecord;
use App\Models\User;
use App\Services\Supervisor\SupervisorFilterService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        return $this->wantsArchive() ? ArchivedCallDetailRecord::class : CallDetailRecord::class;
    }

    /**
     * ?archive=1 switches index, show, export and statistics to the warm tier (6-12 months old). Only organisation
     * API keys may; for the SPA (session users, incl. org Owners) the flag is ignored.
     */
    private function wantsArchive(): bool
    {
        $request = request();

        return $request->boolean('archive') && $request->user() instanceof ApiKey;
    }

    /** The route parameter is the same for both tiers (the archived model would otherwise snake to another name). */
    protected function getRouteParameterName(): string
    {
        return 'call_detail_record';
    }

    protected function getResourceClass(): string
    {
        return CallDetailRecordResource::class;
    }

    protected function getAllowedFilters(): array
    {
        return ['from', 'to', 'disposition', 'from_date', 'to_date', 'user', 'direction'];
    }

    protected function getAllowedSortFields(): array
    {
        // user_full_name needs a join to extensions/users on the main connection, which the warm tier is not on.
        if ($this->wantsArchive()) {
            return ['session_timestamp', 'from', 'to', 'duration', 'billsec', 'disposition', 'direction'];
        }

        return ['session_timestamp', 'from', 'to', 'duration', 'billsec', 'disposition', 'user_full_name', 'direction'];
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
        $query->with(['extension.user:id,name']);

        // Sorting by the assigned user's name requires joining through to a table
        // that isn't directly on call_detail_records. The joined subquery
        // deliberately avoids selecting `organization_id` - joining the raw
        // `extensions` table would pull an ambiguous `organization_id` column into
        // scope and break the unqualified `organization_id` predicate Eloquent's
        // OrganizationScope adds elsewhere in the query. Matching on
        // `cloudonix_uuid` alone is safe since it is Cloudonix-assigned and
        // platform-wide unique, not scoped per organization.
        $sortBy = $request->input('sort_by');
        $organizationId = $request->user()?->organization_id;

        if ($sortBy === 'user_full_name' && ! $this->wantsArchive()) {
            $extensionUsers = DB::table('extensions')
                ->join('users', 'users.id', '=', 'extensions.user_id')
                ->where('extensions.organization_id', $organizationId)
                ->select('extensions.cloudonix_uuid', 'users.name as user_name');

            $query->select('call_detail_records.*')
                ->leftJoinSub($extensionUsers, 'extension_users', function ($join): void {
                    $join->on('extension_users.cloudonix_uuid', '=', 'call_detail_records.subscriber');
                })
                ->addSelect('extension_users.user_name as user_full_name');
        }
    }

    protected function afterShow(Model $model, Request $request): void
    {
        $model->loadMissing(['extension.user:id,name']);
    }

    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Supervisors are always restricted to their scope (the legacy ?supervisor= flag is ignored).
        if ($request->user() !== null) {
            $this->applySupervisorFilter($query, $request->user());
        }

        // Filter by caller number (from)
        if ($request->filled('from')) {
            $this->wherePhonePrefix($query, 'from', (string) $request->input('from'));
        }

        // Filter by called number (to)
        if ($request->filled('to')) {
            $this->wherePhonePrefix($query, 'to', (string) $request->input('to'));
        }

        // Filter by disposition
        if ($request->filled('disposition')) {
            $query->where('disposition', $request->input('disposition'));
        }

        // Filter by session token (used to locate the CDR of a queue call)
        if ($request->filled('session_token')) {
            $query->where('session_token', $request->input('session_token'));
        }

        // Filter by the extension's assigned user (partial name match)
        if ($request->filled('user') && ! $this->wantsArchive()) {
            $search = $request->input('user');
            $query->whereHas('extension.user', function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%');
            });
        }

        // Filter by call direction (incoming/outgoing/internal/application),
        // stored on the CDR at ingest from the session-update webhooks.
        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        // Filter by date range (sargable: plain comparisons on session_timestamp)
        if ($request->filled('from_date') && ($start = $this->parseDay((string) $request->input('from_date'))) !== null) {
            $query->where('session_timestamp', '>=', $start->startOfDay());
        }

        if ($request->filled('to_date') && ($end = $this->parseDay((string) $request->input('to_date'))) !== null) {
            $query->where('session_timestamp', '<=', $end->endOfDay());
        }
    }

    /**
     * Phone-number search, with or without the leading '+'. '*' and '%' are wildcards for any characters;
     * a term without a wildcard is a prefix match (served by cdr_org_from_idx / cdr_org_to_idx). A leading
     * wildcard (contains / ends-with) works but cannot use the index. '_' and '\' stay literal.
     */
    private function wherePhonePrefix(Builder $query, string $column, string $value): void
    {
        $pattern = str_replace(['*', '%'], '%', addcslashes(ltrim($value, '+'), '_\\'));
        if (! str_contains($pattern, '%')) {
            $pattern .= '%';
        }

        $query->where(function (Builder $q) use ($column, $pattern): void {
            $q->where($column, 'like', $pattern);
            if (! str_starts_with($pattern, '%')) {
                $q->orWhere($column, 'like', '+'.$pattern);
            }
        });
    }

    private function parseDay(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Apply Supervisor resource filter to a CDR query.
     */
    private function applySupervisorFilter(Builder $query, User|ApiKey $user): void
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
     * Stream a call's stored recording for playback.
     *
     * Route model binding scopes this to the authenticated user's
     * organization via CallDetailRecord's OrganizationScope, but that alone
     * doesn't apply the supervisor resource-scoping CallDetailRecordPolicy::view()
     * enforces - authorize() below is required, not just defense in depth.
     */
    public function recording(CallDetailRecord $call_detail_record): StreamedResponse|JsonResponse
    {
        $this->authorize('playRecording', $call_detail_record);

        if ($call_detail_record->recording_status !== 'available' || ! $call_detail_record->recording_stored_path) {
            return response()->json(['error' => 'Recording not available'], 404);
        }

        $storagePath = "{$call_detail_record->organization_id}/{$call_detail_record->recording_stored_path}";

        if (! Storage::disk('recordings')->exists($storagePath)) {
            return response()->json(['error' => 'Recording file not found in storage'], 404);
        }

        $disk = Storage::disk('recordings');

        return response()->stream(function () use ($disk, $storagePath): void {
            $source = $disk->readStream($storagePath);
            $output = fopen('php://output', 'w');
            stream_copy_to_stream($source, $output);
            fclose($source);
            fclose($output);
        }, 200, [
            'Content-Type' => $call_detail_record->recording_mime_type ?? 'audio/mpeg',
            'Content-Length' => $disk->size($storagePath),
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * Export CDRs to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->getAuthenticatedUser();

        // Build query with same filters as index
        $query = $this->getModelClass()::forOrganization($user->organization_id);

        $this->applyCustomFilters($query, $request);

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
    private function buildStatisticsQuery(User|ApiKey $user, string $fromDate, string $toDate): Builder
    {
        $query = $this->getModelClass()::forOrganization($user->organization_id)
            ->whereDate('session_timestamp', '>=', $fromDate)
            ->whereDate('session_timestamp', '<=', $toDate);

        $this->applySupervisorFilter($query, $user);

        return $query;
    }
}
