<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Queue call report rows: paginated listing and CSV export for history reports.
 */
class QueueCallController extends Controller
{
    /**
     * Paginated report rows for the organization's queues.
     *
     * Filters: queue_id, disposition, from, to.
     */
    public function index(Request $request): JsonResponse
    {
        $this->guard($request);

        $user = $request->user();

        $request->validate([
            'queue_id' => ['nullable', 'integer'],
            'disposition' => ['nullable', 'in:answered,abandoned,overflow'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $user->organization_id)
            ->when($request->filled('queue_id'), fn ($q) => $q->where('call_queue_id', (int) $request->input('queue_id')))
            ->when($request->filled('disposition'), fn ($q) => $q->where('disposition', $request->input('disposition')))
            ->when($request->date('from'), fn ($q, $from) => $q->where('entered_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->where('entered_at', '<=', $to))
            ->orderByDesc('entered_at');

        $rows = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data' => $rows->map(fn (QueueCall $call) => $this->row($call))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * CSV export of queue call report rows.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->guard($request);

        $user = $request->user();

        $request->validate([
            'queue_id' => ['nullable', 'integer'],
            'disposition' => ['nullable', 'in:answered,abandoned,overflow'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $filename = 'queue-calls-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($request, $user) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Queue Call ID', 'Queue ID', 'Call ID', 'From', 'To', 'Entered At', 'Answered At',
                'Abandoned At', 'Ended At', 'Agent User ID', 'Waiting Seconds', 'Handling Seconds', 'Disposition']);

            QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $user->organization_id)
                ->when($request->filled('queue_id'), fn ($q) => $q->where('call_queue_id', (int) $request->input('queue_id')))
                ->when($request->filled('disposition'), fn ($q) => $q->where('disposition', $request->input('disposition')))
                ->when($request->date('from'), fn ($q, $from) => $q->where('entered_at', '>=', $from))
                ->when($request->date('to'), fn ($q, $to) => $q->where('entered_at', '<=', $to))
                ->orderByDesc('entered_at')
                ->chunk(1000, function ($rows) use ($out) {
                    foreach ($rows as $call) {
                        fputcsv($out, [
                            $call->id,
                            $call->call_queue_id,
                            $call->call_id,
                            $call->from_number,
                            $call->to_number,
                            $call->entered_at?->toIso8601String(),
                            $call->answered_at?->toIso8601String(),
                            $call->abandoned_at?->toIso8601String(),
                            $call->ended_at?->toIso8601String(),
                            $call->agent_user_id,
                            $call->waiting_seconds,
                            $call->handling_seconds,
                            $call->disposition?->value,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Queue reports are readable by the same roles that can view queues.
     */
    private function guard(Request $request): void
    {
        $role = $request->user()->role;

        abort_unless(
            $role->canManageConfiguration() || $role->canViewReports()
                || $role === \App\Enums\UserRole::PBX_USER,
            403
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(QueueCall $call): array
    {
        return [
            'id' => $call->id,
            'call_queue_id' => $call->call_queue_id,
            'call_id' => $call->call_id,
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
            'entered_at' => $call->entered_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'abandoned_at' => $call->abandoned_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'agent_user_id' => $call->agent_user_id,
            'waiting_seconds' => $call->waiting_seconds,
            'handling_seconds' => $call->handling_seconds,
            'disposition' => $call->disposition?->value,
        ];
    }
}
