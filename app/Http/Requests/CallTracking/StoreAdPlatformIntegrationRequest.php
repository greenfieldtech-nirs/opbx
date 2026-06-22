<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdPlatformIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'update',
            [CallTrackingAdPlatformIntegration::class, $this->user()?->organization]
        ) ?? false;
    }

    public function rules(): array
    {
        return [
            'google_ads_enabled' => ['required', 'boolean'],
            'google_ads_developer_token' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:1024'],
            'google_ads_refresh_token' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:8192'],
            'google_ads_customer_id' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:255'],
            'google_ads_conversion_action_resource_name' => ['nullable', 'required_if:google_ads_enabled,true', 'string', 'max:1024'],
            'meta_enabled' => ['required', 'boolean'],
            'meta_pixel_id' => ['nullable', 'required_if:meta_enabled,true', 'string', 'max:255'],
            'meta_access_token' => ['nullable', 'required_if:meta_enabled,true', 'string', 'max:8192'],
        ];
    }
}
