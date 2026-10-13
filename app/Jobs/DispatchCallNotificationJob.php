<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallNotificationsSettings;
use App\Scopes\OrganizationScope;
use App\Services\CallNotifications\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one call-notification webhook to the customer's endpoint, one attempt per run,
 * so a slow or failing customer endpoint never blocks the Cloudonix webhook request.
 */
class DispatchCallNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $settingsId;

    public int $tries;

    public int $timeout;

    /** @var array<int, int> Delay before each retry: the settings' retry_backoff_seconds, doubling, capped at 1 h. */
    public array $backoff = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        CallNotificationsSettings $settings,
        public readonly array $payload,
        public readonly string $eventId,
        public readonly string $sessionToken,
    ) {
        $this->settingsId = $settings->id;
        $this->tries = ($settings->retry_attempts ?? 3) + 1;
        $base = $settings->retry_backoff_seconds ?? 60;
        $this->backoff = array_map(fn (int $retry): int => min($base * 2 ** $retry, 3600), range(0, max($this->tries - 2, 0)));
        $this->timeout = ($settings->request_timeout_seconds ?? 30) + 5;
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $settings = OrganizationScope::bypass(fn () => CallNotificationsSettings::find($this->settingsId));

        if (! $settings || ! $settings->isConfigured()) {
            return;
        }

        $attempt = $this->attempts();
        $result = $dispatcher->deliver($settings, $this->payload, $this->eventId, $this->sessionToken, $attempt);

        if (! $result['delivered'] && $result['retryable'] && $attempt < $this->tries) {
            $this->release($this->backoff[min($attempt - 1, count($this->backoff) - 1)]);
        }
    }
}
