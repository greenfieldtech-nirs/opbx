<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingNotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CallTrackingNotificationLog */
class CallTrackingNotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'call_id' => $this->call_id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'webhook_url' => $this->webhook_url,
            'response_status_code' => $this->response_status_code,
            'response_time_ms' => $this->response_time_ms,
            'is_success' => $this->is_success,
            'attempt_number' => $this->attempt_number,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
