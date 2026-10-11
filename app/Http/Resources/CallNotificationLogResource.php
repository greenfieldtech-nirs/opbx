<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallNotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CallNotificationLog
 */
class CallNotificationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'call_session_token' => $this->call_session_token,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'webhook_url' => $this->webhook_url,
            'request_payload' => $this->request_payload,
            'request_headers' => $this->redact($this->request_headers),
            'request_body' => $this->request_body,
            'response_status_code' => $this->response_status_code,
            'response_body' => $this->response_body,
            'response_headers' => $this->response_headers,
            'response_time_ms' => $this->response_time_ms,
            'attempt_number' => $this->attempt_number,
            'is_success' => $this->is_success,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Auth headers were logged verbatim by the dispatcher; never echo them back.
     *
     * @param  array<string, mixed>|null  $headers
     * @return array<string, mixed>|null
     */
    private function redact(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (preg_match('/authorization|token|key|secret|signature/i', (string) $name)) {
                $headers[$name] = '[redacted]';
            }
        }

        return $headers;
    }
}
