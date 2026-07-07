<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Jobs\DispatchCallTrackingWebhookJob;
use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Str;

class CallTrackingEventDispatcher
{
    public function __construct(
        private readonly CallTrackingWebhookDispatcher $webhookDispatcher,
    ) {}

    public function dispatch(CallTrackingSession $session): void
    {
        $settings = OrganizationScope::bypass(
            fn () => CallTrackingNotificationSettings::forCampaign($session->call_tracking_campaign_id)->first()
        );

        if (! $settings || ! $settings->is_active) {
            return;
        }

        foreach ($this->deriveEventTypes($session) as $eventType) {
            if (! $settings->isEventEnabled($eventType)) {
                continue;
            }

            $eventId = 'ct_event_'.Str::uuid();

            DispatchCallTrackingWebhookJob::dispatch(
                $settings->id,
                $session->id,
                $eventType,
                $eventId,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function deriveEventTypes(CallTrackingSession $session): array
    {
        $events = ['call.received'];

        if ($session->is_answered) {
            $events[] = 'call.answered';
        }

        $isFailed = in_array($session->disposition, ['FAILED', 'BUSY'], true);

        if (! $session->is_answered && ! $isFailed) {
            $events[] = 'call.missed';
        }

        if ($session->is_converted) {
            $events[] = 'call.converted';
        }

        if ($isFailed) {
            $events[] = 'call.failed';
        }

        return $events;
    }
}
