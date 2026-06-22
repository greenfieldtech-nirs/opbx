<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Models\CallTrackingSession;

class NotificationPayloadBuilder
{
    /**
     * Build a webhook notification payload for a call tracking session event.
     *
     * @return array<string, mixed>
     */
    public function build(CallTrackingSession $session, string $eventType, string $eventId): array
    {
        return [
            'event' => $eventType,
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'organization_id' => $session->organization_id,
            'campaign' => [
                'id' => $session->call_tracking_campaign_id,
                'name' => $session->campaign_name,
            ],
            'tracking_number' => $session->called_number,
            'caller_number' => $session->caller_number,
            'source' => $session->source,
            'medium' => $session->medium,
            'duration' => $session->duration,
            'billsec' => $session->billsec,
            'is_answered' => $session->is_answered,
            'is_converted' => $session->is_converted,
            'conversion_value' => $session->conversion_value,
        ];
    }
}
