<?php

declare(strict_types=1);

namespace App\Console\Commands\AutoDialer;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Laravel owns the CAC (concurrent active calls) count: the Go worker only reserves a slot
 * atomically, Laravel releases it on CDR, and this command heals any drift by setting
 * dialer:cac:{id}:active to the number of non-terminal sessions younger than the max call duration.
 */
class ReconcileCacCounters extends Command
{
    /** Sessions older than this are treated as stuck, not as live calls. */
    public const MAX_CALL_DURATION_HOURS = 2;

    protected $signature = 'dialer:reconcile-cac';

    protected $description = 'Set each campaign CAC counter to its number of live call sessions';

    public function handle(): int
    {
        $redis = Redis::connection('dialer');

        // shortcut: counts sessions at one instant; a slot reserved by the worker but not yet
        // backed by a session (a ~1s window) can be under-counted, upgrade if CAC overshoot is seen.
        $counts = OrganizationScope::bypass(fn () => AutoDialerCallSession::query()
            ->active()
            ->where('created_at', '>=', now()->subHours(self::MAX_CALL_DURATION_HOURS))
            ->selectRaw('campaign_id, COUNT(*) as live')
            ->groupBy('campaign_id')
            ->pluck('live', 'campaign_id')
            ->all());

        // Running campaigns with no live sessions must be zeroed too.
        $running = OrganizationScope::bypass(fn () => AutoDialerCampaign::query()
            ->whereIn('status', [CampaignStatus::ACTIVE, CampaignStatus::PAUSED])
            ->pluck('id')
            ->all());

        foreach (array_unique(array_merge(array_keys($counts), $running)) as $campaignId) {
            $live = (int) ($counts[$campaignId] ?? 0);
            $key = "dialer:cac:{$campaignId}:active";
            $before = (int) $redis->get($key);

            if ($before !== $live) {
                $redis->set($key, $live);
                Log::info('CAC counter reconciled', ['campaign_id' => $campaignId, 'before' => $before, 'after' => $live]);
            }
        }

        return self::SUCCESS;
    }
}
