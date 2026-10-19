<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the current set of genuinely active calls for an organization
 * from the session_updates table.
 *
 * session_updates stores one row per status transition, not one row per
 * call, and Cloudonix occasionally delivers a stale "active" status after
 * the call has already been finalized. Callers must therefore always
 * de-duplicate to the latest row per session and exclude sessions that
 * have ever been finalized/deleted - never query session_updates for
 * "active calls" directly (e.g. `whereIn('status', [...])->count()`),
 * or completed/duplicate rows will be miscounted as separate active calls.
 */
class ActiveCallsService
{
    /**
     * Get the latest session_updates row for each currently active session in
     * an organization, optionally restricted to calls placed from or to one
     * of the given caller_id/destination identifiers (e.g. a supervisor's
     * assigned extension numbers/user IDs).
     *
     * @param  array<int, string>|null  $identifiers  Pass null for no restriction
     *                                                (e.g. an owner/admin view). Pass
     *                                                an array to restrict to sessions
     *                                                whose caller_id or destination
     *                                                matches one of these values - an
     *                                                empty array means "restricted to
     *                                                nothing", not "unrestricted"
     *                                                (e.g. a supervisor with no
     *                                                assigned resources sees no calls).
     * @return Collection<int, object> Raw session_updates rows, one per active call: originated
     *                                 conference legs are folded into their caller's row as `legs`.
     */
    public function forOrganization(int $organizationId, ?array $identifiers = null): Collection
    {
        // Sessions that have been completed or deleted should never appear as
        // active, even if a stale "active" status row exists after the fact.
        // Look back 24 hours to catch any recent completions.
        $completedSessionIds = DB::table('session_updates')
            ->where('organization_id', $organizationId)
            ->whereIn('action', ['cdr_final_status', 'deleted'])
            ->where('updated_at', '>=', now()->subHours(24))
            ->pluck('session_id')
            ->unique();

        // Only consider calls active if they've been updated recently (within
        // the last 30 minutes), to avoid stale records when Cloudonix
        // webhooks fail or are delayed.
        $activeCutoff = now()->subMinutes(30);

        $subquery = DB::table('session_updates')
            ->select('session_id', DB::raw('MAX(id) as max_id'))
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['processing', 'ringing', 'connected', 'answer'])
            ->where('updated_at', '>=', $activeCutoff)
            ->groupBy('session_id');

        $calls = DB::table('session_updates as su1')
            ->select('su1.*')
            ->joinSub($subquery, 'su2', function ($join) {
                $join->on('su1.session_id', '=', 'su2.session_id')
                    ->on('su1.id', '=', 'su2.max_id');
            })
            ->where('su1.organization_id', $organizationId)
            ->whereNotIn('su1.session_id', $completedSessionIds)
            ->whereNotIn('su1.action', ['deleted', 'cdr_final_status'])
            ->orderBy('su1.updated_at', 'desc')
            ->get();

        $calls = $this->foldConferenceLegs($calls);

        if ($identifiers === null) {
            return $calls;
        }

        // A call is in scope when any of its legs is (e.g. the queue agent's extension).
        $identifiers = array_map('strval', $identifiers);

        return $calls->filter(fn ($call) => collect([$call, ...$call->legs])->contains(
            fn ($leg) => in_array((string) $leg->caller_id, $identifiers, true)
                || in_array((string) $leg->destination, $identifiers, true)
        ))->values();
    }

    /**
     * Agent/member legs originated for a caller (queue "ring agents in separate calls", simultaneous
     * ring groups) are the same call as the caller's session: drop them from the list and hang them
     * off the caller's row as `legs`. A leg whose caller is no longer active stays a row of its own.
     */
    private function foldConferenceLegs(Collection $calls): Collection
    {
        $callers = app(ConferenceLegLinks::class)->callersOf($calls->pluck('session_token')->all());
        $byToken = $calls->whereNotNull('session_token')->keyBy('session_token');

        foreach ($calls as $call) {
            $call->legs = [];
        }

        return $calls->reject(function ($call) use ($callers, $byToken): bool {
            $caller = $byToken->get($callers[$call->session_token ?? ''] ?? '');
            if ($caller === null || $caller === $call) {
                return false;
            }

            $caller->legs[] = $call;

            return true;
        })->values();
    }
}
