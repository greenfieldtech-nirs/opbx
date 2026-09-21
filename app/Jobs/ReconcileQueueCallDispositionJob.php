<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\QueueCallLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Re-evaluates a queue call's disposition after a grace period, when the CDR
 * arrived before the dial action callback (their ordering is not guaranteed).
 *
 * The CDR payload is parked in Redis (`acd:cdr:{call_id}`) while waiting.
 */
class ReconcileQueueCallDispositionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $queueCallId,
    ) {}

    public function handle(QueueCallLifecycleService $lifecycle): void
    {
        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)->find($this->queueCallId);

        if (! $queueCall || $queueCall->disposition !== null) {
            return;
        }

        $payloadJson = Redis::get("acd:cdr:{$queueCall->call_id}");

        if (! $payloadJson) {
            Log::warning('Queue disposition reconcile: parked CDR payload missing', [
                'queue_call_id' => $this->queueCallId,
            ]);

            return;
        }

        Redis::del("acd:cdr:{$queueCall->call_id}");

        $lifecycle->finalizeFromCdr($queueCall->organization_id, json_decode($payloadJson, true));
    }
}
