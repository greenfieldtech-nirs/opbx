<?php

declare(strict_types=1);

namespace App\Services\AiMonitor;

use App\Enums\AiCallAttemptStatus;
use App\Enums\AlbsStrategy;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiCallAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the AI Real Time Monitor, for the current organisation (tenant scope),
 * over the last N minutes. Aggregated in SQL: the page polls every few seconds.
 */
class AiMonitorStats
{
    /** Points in each sparkline. */
    public const BUCKETS = 12;

    // shortcut: an attempt whose CDR never arrived stops counting as active after 4 h; upgrade if calls run longer
    private const ACTIVE_HORIZON_HOURS = 4;

    public function snapshot(int $minutes): array
    {
        $now = now();
        $from = $now->copy()->subMinutes($minutes);
        $bucketSeconds = max(1, intdiv($minutes * 60, self::BUCKETS));

        return [
            'window_minutes' => $minutes,
            'bucket_seconds' => $bucketSeconds,
            'generated_at' => $now->toIso8601String(),
            'assistants' => $this->assistants($from, $bucketSeconds),
            'load_balancers' => $this->loadBalancers($from, $bucketSeconds),
        ];
    }

    private function assistants(Carbon $from, int $bucketSeconds): array
    {
        $totals = $this->inWindow($from)->whereNotNull('ai_assistant_id')
            ->groupBy('ai_assistant_id')
            ->selectRaw("ai_assistant_id,
                COUNT(*) AS calls,
                SUM(status = 'answered') AS answered,
                SUM(status = 'failed') AS failed,
                AVG(CASE WHEN status = 'answered' THEN billsec END) AS avg_billsec,
                COALESCE(SUM(CASE WHEN status = 'answered' THEN billsec END), 0) AS billsec_total")
            ->get()->keyBy('ai_assistant_id');

        $active = $this->active()->whereNotNull('ai_assistant_id')
            ->groupBy('ai_assistant_id')->selectRaw('ai_assistant_id, COUNT(*) AS n')
            ->pluck('n', 'ai_assistant_id');

        // Beyond the window on purpose: "last call 4h ago" is what flags an idle assistant.
        // Newest attempt per assistant via the (ai_assistant_id, started_at) index, not a retention scan.
        $lastCall = $this->counted()->select('ai_call_attempts.started_at')
            ->whereColumn('ai_call_attempts.ai_assistant_id', 'ai_assistants.id')
            ->orderByDesc('ai_call_attempts.started_at')
            ->limit(1);

        $series = $this->series($from, $bucketSeconds, 'ai_assistant_id', 'COUNT(*)');

        return AiAssistant::query()->orderBy('name')
            ->select(['ai_assistants.id', 'ai_assistants.name', 'ai_assistants.provider', 'ai_assistants.status'])
            ->addSelect(['last_call_at' => $lastCall])
            ->get()
            ->map(function (AiAssistant $assistant) use ($totals, $active, $series) {
                $row = $totals->get($assistant->id);
                $answered = (int) ($row->answered ?? 0);
                $failed = (int) ($row->failed ?? 0);

                return [
                    'id' => $assistant->id,
                    'name' => $assistant->name,
                    'provider' => $assistant->provider,
                    'status' => $this->value($assistant->status),
                    'active' => (int) ($active[$assistant->id] ?? 0),
                    'calls' => (int) ($row->calls ?? 0),
                    'answered' => $answered,
                    'failed' => $failed,
                    'success_rate' => $this->rate($answered, $answered + $failed),
                    'avg_duration_seconds' => isset($row->avg_billsec) ? (int) round((float) $row->avg_billsec) : null,
                    'ai_minutes' => (int) round(((int) ($row->billsec_total ?? 0)) / 60),
                    'last_call_at' => $assistant->last_call_at !== null ? Carbon::parse($assistant->last_call_at)->toIso8601String() : null,
                    'series' => $series[$assistant->id] ?? array_fill(0, self::BUCKETS, 0),
                ];
            })->all();
    }

    private function loadBalancers(Carbon $from, int $bucketSeconds): array
    {
        $totals = $this->inWindow($from)->whereNotNull('ai_assistant_load_balancer_id')
            ->groupBy('ai_assistant_load_balancer_id')
            ->selectRaw('ai_assistant_load_balancer_id AS lb_id,
                COUNT(DISTINCT call_id) AS calls,
                SUM(failed_over) AS failovers,
                COUNT(DISTINCT CASE WHEN fell_back THEN call_id END) AS fell_back')
            ->get()->keyBy('lb_id');

        $active = $this->active()->whereNotNull('ai_assistant_load_balancer_id')
            ->groupBy('ai_assistant_load_balancer_id')
            ->selectRaw('ai_assistant_load_balancer_id AS lb_id, COUNT(DISTINCT call_id) AS n')
            ->pluck('n', 'lb_id');

        $memberCalls = $this->inWindow($from)->whereNotNull('ai_assistant_load_balancer_id')->whereNotNull('ai_assistant_id')
            ->groupBy('ai_assistant_load_balancer_id', 'ai_assistant_id')
            ->selectRaw('ai_assistant_load_balancer_id AS lb_id, ai_assistant_id, COUNT(*) AS n')
            ->get()->groupBy('lb_id');

        $series = $this->series($from, $bucketSeconds, 'ai_assistant_load_balancer_id', 'COUNT(DISTINCT call_id)');

        return AiAssistantLoadBalancer::query()->with('members.aiAssistant:id,name')->orderBy('name')->get()
            ->map(function (AiAssistantLoadBalancer $lb) use ($totals, $active, $memberCalls, $series) {
                $row = $totals->get($lb->id);
                $calls = (int) ($row->calls ?? 0);
                $fellBack = (int) ($row->fell_back ?? 0);

                return [
                    'id' => $lb->id,
                    'name' => $lb->name,
                    'strategy' => $this->value($lb->strategy),
                    'status' => $this->value($lb->status),
                    'active' => (int) ($active[$lb->id] ?? 0),
                    'calls' => $calls,
                    'failovers' => (int) ($row->failovers ?? 0),
                    'fell_back' => $fellBack,
                    // Share of calls an AI assistant handled without the fallback taking over.
                    'handled_rate' => $this->rate($calls - $fellBack, $calls),
                    'members' => $this->members($lb, collect($memberCalls->get($lb->id, []))->pluck('n', 'ai_assistant_id')),
                    'series' => $series[$lb->id] ?? array_fill(0, self::BUCKETS, 0),
                ];
            })->all();
    }

    /** Each member's actual share of the load balancer's attempts next to its configured share. */
    private function members(AiAssistantLoadBalancer $lb, Collection $calls): array
    {
        $active = $lb->members->filter(fn ($m) => $this->value($m->status) === 'active');
        $totalCalls = (int) $calls->sum();
        $totalWeight = (int) $active->sum('weight');
        $strategy = $lb->strategy instanceof AlbsStrategy ? $lb->strategy : AlbsStrategy::tryFrom((string) $lb->strategy);

        return $lb->members->sortBy('priority')->values()->map(function ($member) use ($active, $calls, $totalCalls, $totalWeight, $strategy) {
            $isActive = $active->contains('id', $member->id);
            $n = (int) ($calls[$member->ai_assistant_id] ?? 0);

            return [
                'ai_assistant_id' => $member->ai_assistant_id,
                'name' => $member->aiAssistant?->name,
                'active' => $isActive,
                'priority' => $member->priority,
                'calls' => $n,
                'actual_share' => $this->rate($n, $totalCalls),
                'configured_share' => match (true) {
                    ! $isActive => null,
                    $strategy === AlbsStrategy::PERCENTAGE => $this->rate((int) $member->weight, $totalWeight),
                    $strategy === AlbsStrategy::ROUND_ROBIN => $this->rate(1, $active->count()),
                    default => null, // priority: an order, not a share
                },
            ];
        })->all();
    }

    /** @return array<int, array<int, int>> id => calls per bucket, oldest first */
    private function series(Carbon $from, int $bucketSeconds, string $column, string $count): array
    {
        $rows = $this->inWindow($from)->whereNotNull($column)
            ->selectRaw("{$column} AS id, FLOOR(TIMESTAMPDIFF(SECOND, ?, started_at) / ?) AS bucket, {$count} AS n", [$from, $bucketSeconds])
            ->groupBy('id', 'bucket')
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $series[$row->id] ??= array_fill(0, self::BUCKETS, 0);
            $bucket = min(self::BUCKETS - 1, max(0, (int) $row->bucket));
            $series[$row->id][$bucket] += (int) $row->n;
        }

        return $series;
    }

    /** Attempts that count as AI calls (auto-dialer calls that reached voicemail do not). */
    private function counted(): Builder
    {
        return AiCallAttempt::query()->where('ai_call_attempts.status', '!=', AiCallAttemptStatus::EXCLUDED->value);
    }

    private function inWindow(Carbon $from): Builder
    {
        return $this->counted()->where('started_at', '>=', $from);
    }

    private function active(): Builder
    {
        return AiCallAttempt::query()
            ->where('status', AiCallAttemptStatus::IN_PROGRESS->value)
            ->where('started_at', '>=', now()->subHours(self::ACTIVE_HORIZON_HOURS));
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    private function value(mixed $enum): ?string
    {
        return $enum instanceof \BackedEnum ? (string) $enum->value : ($enum !== null ? (string) $enum : null);
    }
}
