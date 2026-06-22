<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\CallTrackingWebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCallTrackingWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $settingsId,
        public readonly int $sessionId,
        public readonly string $eventType,
        public readonly string $eventId,
    ) {}

    public function handle(CallTrackingWebhookDispatcher $dispatcher): void
    {
        $settings = OrganizationScope::bypass(
            fn () => CallTrackingNotificationSettings::find($this->settingsId)
        );

        if (! $settings || ! $settings->is_active || ! $settings->isEventEnabled($this->eventType)) {
            return;
        }

        $session = OrganizationScope::bypass(
            fn () => CallTrackingSession::find($this->sessionId)
        );

        if (! $session) {
            return;
        }

        $dispatcher->dispatch($settings, $session, $this->eventType, $this->eventId);
    }
}
