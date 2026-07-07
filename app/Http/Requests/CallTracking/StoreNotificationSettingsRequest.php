<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request validator for creating or updating call tracking notification settings.
 */
class StoreNotificationSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var CallTrackingCampaign $campaign */
        $campaign = $this->route('callTrackingCampaign');

        $settings = CallTrackingNotificationSettings::forCampaign($campaign->id)->first()
            ?? new CallTrackingNotificationSettings([
                'organization_id' => $campaign->organization_id,
                'call_tracking_campaign_id' => $campaign->id,
            ]);

        return $this->user()?->can('update', $settings) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'max:2048'],
            'auth_method' => ['required', 'in:none,bearer_token,basic_auth'],
            'auth_secret' => ['nullable', 'required_if:auth_method,bearer_token,basic_auth', 'max:2048'],
            'auth_username' => ['nullable', 'required_if:auth_method,basic_auth', 'max:255'],
            'enabled_events' => ['required', 'array'],
            'enabled_events.*' => ['in:call.received,call.answered,call.missed,call.converted,call.failed'],
            'is_active' => ['boolean'],
        ];
    }
}
