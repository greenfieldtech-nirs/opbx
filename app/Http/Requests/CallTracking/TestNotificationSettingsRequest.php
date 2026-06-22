<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('callTrackingCampaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('update', $campaign) ?? false);
    }

    public function rules(): array
    {
        return [
            'event_type' => ['nullable', 'string', Rule::in(CallTrackingEventType::values())],
        ];
    }
}
