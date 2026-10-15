<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\VoiceRouting\RingGroupConferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ring timeout of a simultaneous ring group (members ringing in separate calls): when nobody answered by
 * then, the members still ringing are ended and the caller is switched to the fallback. No-op once a member
 * answered, the fallback already ran, or the caller left.
 */
class ExpireRingGroupOfferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $ringGroupId,
        public readonly int $organizationId,
        public readonly string $callerToken,
    ) {}

    public function handle(RingGroupConferenceService $conference): void
    {
        $conference->fallBack($this->ringGroupId, $this->organizationId, $this->callerToken);
    }
}
