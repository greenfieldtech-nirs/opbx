<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CallTrackingNotificationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call Tracking Notification Settings API Resource.
 *
 * @mixin CallTrackingNotificationSettings
 */
class CallTrackingNotificationSettingsResource extends JsonResource
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
            'call_tracking_campaign_id' => $this->call_tracking_campaign_id,
            'webhook_url' => $this->webhook_url,
            'auth_method' => $this->auth_method,
            'auth_username' => $this->auth_username,
            'enabled_events' => $this->enabled_events,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
