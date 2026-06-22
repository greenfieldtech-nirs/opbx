<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('callTrackingCampaign');

        return $campaign instanceof CallTrackingCampaign
            && ($this->user()?->can('view', $campaign) ?? false);
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'event_type' => ['nullable', 'string', Rule::in(CallTrackingEventType::values())],
            'success' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
