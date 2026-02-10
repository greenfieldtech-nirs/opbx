<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Notifications Settings Resource.
 *
 * @mixin \App\Models\CallNotificationsSettings
 */
class CallNotificationsSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'webhook_url' => $this->webhook_url,
            'auth_method' => $this->auth_method,
            'auth_username' => $this->auth_username,
            // Don't expose auth_secret for security
            'has_auth_secret' => ! empty($this->auth_secret),
            'retry_attempts' => $this->retry_attempts,
            'retry_backoff_seconds' => $this->retry_backoff_seconds,
            'request_timeout_seconds' => $this->request_timeout_seconds,
            'enabled_events' => $this->enabled_events,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
