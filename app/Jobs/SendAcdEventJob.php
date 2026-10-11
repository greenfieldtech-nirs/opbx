<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CallQueue\AcdWorkerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Replays an ACD event the worker did not accept inline (restart, network blip), so agents
 * and callers are not left stuck in BUSY / waiting.
 */
class SendAcdEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 300];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload) {}

    public function handle(AcdWorkerClient $worker): void
    {
        if (! $worker->sendEvent($this->payload)) {
            throw new \RuntimeException('ACD worker did not accept the event; will retry');
        }
    }
}
